# main_fixedgatt.py
"""
Sound Monitor IoT
ESP32 Sound Sensor Firmware

Hardware
- XIAO ESP32S3
- ICS-43434 MEMS Microphone

Function
- Measure sound level
- Calculate RMS
- Convert to relative dB
- Send data via BLE
"""
import time
import struct

try:
    import bluetooth
except ImportError:
    import ubluetooth as bluetooth

from soundsensor_db import SoundSensor

NAME_PREFIX = "RP-SoundSensor-"
WINDOW_MS = 3000
SAMPLE_INTERVAL_MS = 100

ENABLE_SECURITY = False
REQUIRE_ENCRYPTED_TO_NOTIFY = False

_SOUND_SERVICE_UUID = bluetooth.UUID("c0de0001-3b3a-4f2c-9a3a-1b2c3d4e5001")
_SOUND_LEVEL_UUID   = bluetooth.UUID("c0de0002-3b3a-4f2c-9a3a-1b2c3d4e5001")
_BASELINE_UUID      = bluetooth.UUID("c0de0003-3b3a-4f2c-9a3a-1b2c3d4e5001")
_AUX1_UUID          = bluetooth.UUID("c0de0004-3b3a-4f2c-9a3a-1b2c3d4e5001")
_AUX2_UUID          = bluetooth.UUID("c0de0005-3b3a-4f2c-9a3a-1b2c3d4e5001")

_FLAG_READ = bluetooth.FLAG_READ
_FLAG_NOTIFY = bluetooth.FLAG_NOTIFY

_IRQ_CENTRAL_CONNECT = 1
_IRQ_CENTRAL_DISCONNECT = 2
PERCENTILE = 0.90
MAX_STEP_DB_X10 = 200


def _make_adv_field(field_type: int, value: bytes) -> bytes:
    return bytes((len(value) + 1, field_type)) + value


def _adv_payload_split(name: str):
    adv = _make_adv_field(0x01, b"\x06")
    resp = _make_adv_field(0x09, name.encode())
    return adv, resp


class StatusLED:
    def __init__(self):
        self._mode = "none"
        self._pin = None
        self._np = None

        try:
            import neopixel
            from machine import Pin
            for p in ("NEOPIXEL", "RGB", "RGBLED"):
                try:
                    pin = Pin(p, Pin.OUT)
                    self._np = neopixel.NeoPixel(pin, 1)
                    self._mode = "neopixel"
                    self.off()
                    return
                except Exception:
                    pass
        except Exception:
            pass

        try:
            from machine import Pin
            try:
                self._pin = Pin("LED", Pin.OUT)
                self._mode = "pin"
                self.off()
                return
            except Exception:
                pass
        except Exception:
            pass

    def on(self):
        if self._mode == "pin":
            self._pin.value(1)
        elif self._mode == "neopixel":
            self._np[0] = (20, 20, 0)
            self._np.write()

    def off(self):
        if self._mode == "pin":
            self._pin.value(0)
        elif self._mode == "neopixel":
            self._np[0] = (0, 0, 0)
            self._np.write()

    def toggle(self, state: bool):
        self.on() if state else self.off()


class SoundBlePeripheral:
    def __init__(self):
        self._ble = bluetooth.BLE()
        self._ble.active(True)
        self._ble.irq(self._irq)

        handles = self._ble.gatts_register_services((
            (_SOUND_SERVICE_UUID, (
                (_SOUND_LEVEL_UUID, _FLAG_READ | _FLAG_NOTIFY),
                (_BASELINE_UUID, _FLAG_READ | _FLAG_NOTIFY),
                (_AUX1_UUID, _FLAG_READ | _FLAG_NOTIFY),
                (_AUX2_UUID, _FLAG_READ | _FLAG_NOTIFY),
            )),
        ))
        ((self._level_handle, self._baseline_handle, self._aux1_handle, self._aux2_handle),) = handles
        self._connections = set()

        mac = self._ble.config("mac")
        if isinstance(mac, tuple):
            mac = mac[1]
        self._addr = ":".join("{:02X}".format(b) for b in mac)
        self._name = NAME_PREFIX + self._addr.replace(":", "")[-4:]

        self._adv_data, self._resp_data = _adv_payload_split(self._name)
        self._advertise()
        print("GATT fixed handles =", handles)

    def _advertise(self, interval_ms=250):
        self._ble.gap_advertise(
            interval_ms * 1000,
            adv_data=self._adv_data,
            resp_data=self._resp_data,
        )
        print("Advertising:", self._name, "| BT Addr:", self._addr)

    def _irq(self, event, data):
        if event == _IRQ_CENTRAL_CONNECT:
            conn_handle, _, _ = data
            self._connections.add(conn_handle)
            print("Connected:", conn_handle)
        elif event == _IRQ_CENTRAL_DISCONNECT:
            conn_handle, _, _ = data
            self._connections.discard(conn_handle)
            print("Disconnected:", conn_handle)
            self._advertise()

    def _notify_u16(self, handle, value_u16: int):
        v = max(0, min(65535, int(value_u16)))
        payload = struct.pack("<H", v)
        self._ble.gatts_write(handle, payload)
        for ch in list(self._connections):
            try:
                self._ble.gatts_notify(ch, handle, payload)
            except Exception:
                pass

    def notify_all(self, level_u16: int, baseline_u16: int, aux1_u16: int = 0, aux2_u16: int = 0):
        self._notify_u16(self._level_handle, level_u16)
        self._notify_u16(self._baseline_handle, baseline_u16)
        self._notify_u16(self._aux1_handle, aux1_u16)
        self._notify_u16(self._aux2_handle, aux2_u16)


def calibrate_with_blink(sensor, led, seconds=2.0, interval_ms=50, blink_ms=150):
    end = time.ticks_add(time.ticks_ms(), int(seconds * 1000))
    vals = []
    led_state = False
    next_blink = time.ticks_ms()

    while time.ticks_diff(end, time.ticks_ms()) > 0:
        vals.append(sensor._read_rms_24())
        now_ms = time.ticks_ms()
        if time.ticks_diff(now_ms, next_blink) >= 0:
            led_state = not led_state
            led.toggle(led_state)
            next_blink = time.ticks_add(now_ms, blink_ms)
        time.sleep_ms(interval_ms)

    led.off()
    return sensor.set_initial_noise_from_values(vals)


def median3(a, b, c):
    return a + b + c - min(a, b, c) - max(a, b, c)


led = StatusLED()
periph = SoundBlePeripheral()

sensor = SoundSensor(
    bclk_pin=7,
    lrclk_pin=8,
    data_pin=9,
    pick="L",
    time_weight="FAST",
    dc_alpha=0.995,
    peak_hold_ms=1000,
)

print("Calibrating noise... keep silent (LED blinking)")
nf = calibrate_with_blink(sensor, led, seconds=2.0, interval_ms=50, blink_ms=150)
print("Initial noise_rms =", nf)

window_start = time.ticks_ms()
window_values = []
prev_rel10 = None
prev1 = None
prev2 = None

while True:
    rms = sensor._read_rms_24()
    updated = sensor.learn_quiet_baseline(rms)
    if updated:
        print("Baseline updated:", sensor.noise_rms)

    rel10 = sensor.rms_to_dbrel_x10(rms)

    if prev_rel10 is not None:
        delta = rel10 - prev_rel10
        if delta > MAX_STEP_DB_X10:
            rel10 = prev_rel10 + MAX_STEP_DB_X10
        elif delta < -MAX_STEP_DB_X10:
            rel10 = max(0, prev_rel10 - MAX_STEP_DB_X10)

    prev_rel10 = rel10
    filtered = rel10 if (prev1 is None or prev2 is None) else median3(prev2, prev1, rel10)
    prev2 = prev1
    prev1 = rel10
    window_values.append(filtered)

    now_ms = time.ticks_ms()
    if time.ticks_diff(now_ms, window_start) >= WINDOW_MS:
        arr = sorted(window_values) if window_values else [0]
        idx = int(len(arr) * PERCENTILE)
        if idx >= len(arr):
            idx = len(arr) - 1
        level = arr[idx]
        baseline_x10 = int(sensor.noise_rms * 10) if sensor.noise_rms is not None else 0
        aux1 = 0
        aux2 = 0
        print("TX level={:.1f} dB, baseline={:.1f}, aux1={}, aux2={}".format(
            level / 10.0, baseline_x10 / 10.0, aux1, aux2
        ))
        periph.notify_all(level, baseline_x10, aux1, aux2)
        window_values = []
        window_start = now_ms

    time.sleep_ms(SAMPLE_INTERVAL_MS)
