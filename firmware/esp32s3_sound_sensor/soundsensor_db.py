from machine import Pin, I2S
import math
import time

LEARN_SAMPLE_INTERVAL_MS = 2000
LEARN_WINDOW_COUNT = 900
LEARN_MIN_COUNT = 120
LEARN_PERCENTILE = 0.10
LEARN_APPLY_RATIO = 0.20
LEARN_REQUIRE_RATIO = 0.98
LEARN_UPDATE_COOLDOWN_MS = 5 * 60 * 1000

class SoundSensor:
    def __init__(
        self,
        bclk_pin,
        lrclk_pin,
        data_pin,
        i2s_id=0,
        sample_rate=32000,
        buffer_frames=1024,
        pick="L",
        time_weight="FAST",
        dc_alpha=0.995,
        peak_hold_ms=1000,
    ):
        self.pick = pick
        self.dc_alpha = dc_alpha
        self._dc = 0.0
        self.noise_rms = None
        self.ema = 0.08 if time_weight == "SLOW" else 0.6
        self._rel_ema = 0.0
        self.peak_hold_ms = peak_hold_ms
        self._peak = 0.0
        self._peak_until = 0
        self._buf = bytearray(buffer_frames * 8)
        self._mv = memoryview(self._buf)

        self.i2s = I2S(
            i2s_id,
            sck=Pin(bclk_pin),
            ws=Pin(lrclk_pin),
            sd=Pin(data_pin),
            mode=I2S.RX,
            bits=32,
            format=I2S.STEREO,
            rate=sample_rate,
            ibuf=len(self._buf) * 2,
        )

        self._learn_values = []
        self._last_learn_sample_ms = time.ticks_ms()
        self._last_learn_update_ms = time.ticks_ms()

        time.sleep_ms(120)
        for _ in range(3):
            try:
                self.i2s.readinto(self._mv)
            except Exception:
                pass
            time.sleep_ms(10)

    @staticmethod
    def _signed32(x):
        x &= 0xFFFFFFFF
        if x & 0x80000000:
            x -= 0x100000000
        return x

    def _read_rms_24(self) -> float:
        n = self.i2s.readinto(self._mv)
        if not n:
            return 0.0
        n = (n // 8) * 8
        if n == 0:
            return 0.0

        offset = 0 if self.pick == "L" else 4
        mv = self._mv
        acc = 0.0
        count = 0
        dc = self._dc
        a = self.dc_alpha

        for i in range(offset, n, 8):
            u = int.from_bytes(mv[i:i+4], "little", False)
            x = self._signed32(u)
            s = x >> 8
            xf = float(s)
            dc = a * dc + (1.0 - a) * xf
            xf = xf - dc
            acc += xf * xf
            count += 1

        self._dc = dc
        if count == 0:
            return 0.0

        rms = math.sqrt(acc / count)
        return max(1.0, rms)

    def set_initial_noise_from_values(self, vals):
        vals = [v for v in vals if v > 0.0]
        vals.sort()
        if not vals:
            self.noise_rms = 1.0
            return self.noise_rms
        cut = max(1, int(len(vals) * 0.2))
        low = vals[:cut]
        self.noise_rms = max(1.0, low[len(low) // 2])
        return self.noise_rms

    def rms_to_dbrel_x10(self, rms: float) -> int:
        if self.noise_rms is None:
            return 0
        rel = 0.0 if rms <= 1e-9 else 20.0 * math.log10(rms / self.noise_rms)
        if rel < 0.0:
            rel = 0.0
        e = self.ema
        self._rel_ema = (1.0 - e) * self._rel_ema + e * rel
        if self.peak_hold_ms and self.peak_hold_ms > 0:
            now = time.ticks_ms()
            if self._rel_ema >= self._peak or time.ticks_diff(self._peak_until, now) <= 0:
                self._peak = self._rel_ema
                self._peak_until = time.ticks_add(now, self.peak_hold_ms)
        return int(self._rel_ema * 10)

    def learn_quiet_baseline(self, rms: float) -> bool:
        if self.noise_rms is None or rms <= 0.0:
            return False
        now = time.ticks_ms()
        if time.ticks_diff(now, self._last_learn_sample_ms) < LEARN_SAMPLE_INTERVAL_MS:
            return False
        self._last_learn_sample_ms = now
        self._learn_values.append(rms)
        if len(self._learn_values) > LEARN_WINDOW_COUNT:
            self._learn_values.pop(0)
        if len(self._learn_values) < LEARN_MIN_COUNT:
            return False
        if time.ticks_diff(now, self._last_learn_update_ms) < LEARN_UPDATE_COOLDOWN_MS:
            return False
        arr = sorted(self._learn_values)
        idx = int(len(arr) * LEARN_PERCENTILE)
        if idx >= len(arr):
            idx = len(arr) - 1
        candidate = max(1.0, arr[idx])
        if candidate < self.noise_rms * LEARN_REQUIRE_RATIO:
            self.noise_rms = max(1.0, (self.noise_rms * (1.0 - LEARN_APPLY_RATIO)) + (candidate * LEARN_APPLY_RATIO))
            self._last_learn_update_ms = now
            return True
        return False
