# ESP32S3 Sound Sensor Firmware
ESP32S3 sound sensor firmware for Sound Monitor IoT.

## Hardware

- Seeed Studio XIAO ESP32S3
- ICS-43434 MEMS microphone

ICS-43434 は I2S 接続のデジタル MEMS マイクです。  
そのため、I2S インターフェースを持つ ESP32-S3 を使用しています。  
ESP32-C3 など、I2S をサポートしていないマイコンでは動作しません。

The ICS-43434 is a digital MEMS microphone that uses the I2S interface.  
Therefore this project uses an ESP32-S3 which supports I2S.  
Microcontrollers without I2S support, such as ESP32-C3, cannot be used.

## Files

- `main.py`  
  メインのファイルです。
  Main firmware entry point
- `soundsensor_db.py`
  RMS計算や相対dB変換などの音声処理ロジックを実装しています
  Sound processing logic such as RMS calculation and relative dB conversion
  

## Overview

このファームウェアは、I2Sマイクから音声サンプルを読み取り、相対的な音量レベルを計算し、BLEで送信します。
This firmware reads audio samples from an I2S microphone, calculates a relative sound level,
and transmits the value via BLE.

---

## Transmitted Values / 送信値

BLEペリフェラルは以下の値を提供します。
The BLE peripheral provides the following values.

### `level`

相対音量レベルです。値は10倍スケールです。
Relative sound level value (x10 scale).

Example:

- `153` = `15.3 dB`
- `87` = `8.7 dB`

これが主な監視値です。
This is the main monitoring value.

---

### `baseline`

現在のベースラインRMS値です。値は10倍スケールです。
これは物理的なdB値ではありません。  
相対音量を計算するための内部基準値です。

Current baseline RMS value (x10 scale).
This is not a physical dB value.
It is an internal reference value used to calculate relative sound level.

---

### `aux1`

予約値です。現在は `0` 固定です。
Reserved value. Currently fixed to `0`.

---

### `aux2`

予約値です。現在は `0` 固定です。
Reserved value. Currently fixed to `0`.

---

## Calculation Method / 計算方法

### 1. RMS calculation / RMS計算

センサーは 32bit stereo の I2S サンプルを読み取り、通常は片チャネル (`pick="L"`) を使用します。  
値は 8bit 右シフトして実質24bit信号として扱います。
The sensor reads 32-bit stereo I2S samples and uses one channel (`pick="L"` by default).
The value is converted to an effective 24-bit signal by shifting right by 8 bits.

RMS計算の前にDC成分の除去を行います。
A DC removal filter is applied before RMS calculation.


RMSはサンプル値の二乗平均平方根として計算します。
The RMS is calculated from the squared sample values.

---

### 2. Initial baseline / 初期ベースライン

起動時に短いキャリブレーションを行い、複数のRMSサンプルを収集します。
収集した値のうち下位20%を取り出し、その中央値を初期 `noise_rms` として採用します。
この方式により、起動時の一時的な物音の影響を受けにくくしています。

At startup, the firmware performs a short calibration.
During this phase, multiple RMS samples are collected.
The lower 20% of the collected values are extracted,
and the median of that lower range is used as the initial `noise_rms`.
This design makes the baseline more resistant to temporary noise during startup.


---

### 3. Relative dB calculation / 相対dB計算

主な音量レベルは次の式で計算されます。
The main sound level is calculated as:
`relative_dB = 20 * log10(rms / noise_rms)`

Important:

重要:

- これは絶対音圧の dB SPL ではありません
- 学習された基準値に対する相対値です
したがって送信値は、「静かな基準状態に対して、現在どれだけ大きいか」を示す値として解釈してください。


- This is **not absolute SPL dB**
- This is a **relative value against the learned baseline**
Therefore, the transmitted value should be interpreted as
“how much louder the current sound is compared with the quiet baseline.”

---

### 4. Smoothing / 平滑化

相対dB値には指数移動平均 (EMA) による平滑化をかけています。
さらに、短時間の突発的なスパイクを抑えるため、3点メディアンフィルタを適用しています。
また、不自然な急変を抑えるため、変化量の上限も設けています。

The relative dB value is smoothed with exponential moving average (EMA).
In addition, a median-of-3 filter is applied to suppress short spikes.
A maximum step limit is also used to avoid unrealistic sudden jumps.

---

### 5. 3-second window aggregation / 3秒窓での集約

センサーは生の値をそのまま毎回送信するのではなく、3秒間の値を集約して送信します。
送信する `level` は、3秒窓内の平滑化済み値の 90 パーセンタイルです。
この方式を採用した理由は次の通りです。

- 意味のある物音に反応しやすい
- 1サンプルだけのスパイクに引きずられにくい
- 瞬間最大値より安定している

The sensor does not transmit every raw sample.
Instead, values are collected for 3 seconds and aggregated.
The transmitted `level` is the 90th percentile of the filtered values in the 3-second window.
This design was chosen because:

- it reacts to meaningful sound events
- it is less sensitive to one-sample spikes
- it is more stable than sending an instantaneous peak

---

## Quiet Baseline Learning / 静寂ベースライン学習

起動後も、静寂時のベースライン学習を継続します。
以下の条件を満たしたときのみ更新します。

- 十分なサンプル数が集まっている
- 更新クールダウン時間を過ぎている
- 候補値が現在のベースラインより十分低い

更新は即時置き換えではなく、徐々に反映されます。
これにより、長期的な環境変化には追従しつつ、不安定な動作を避けています。

After startup, the firmware continues learning the quiet baseline.
It periodically stores RMS samples and updates the baseline only when:

- enough samples have been collected
- the cooldown period has passed
- the candidate value is sufficiently lower than the current baseline

The update is applied gradually instead of replacing the baseline immediately.
This helps the sensor adapt to long-term environmental changes while avoiding unstable behavior.


---

## Important Notes / 注意事項

- 送信される `level` は **相対指標** であり、校正済みの音圧レベルではありません。
- マイク個体差や取り付け条件により生値は変動します。
- この値は、傾向監視、相対比較、しきい値判定に向いています。

- The transmitted `level` is a **relative index**, not a calibrated SPL measurement.
- Different microphones and mounting conditions may produce different raw levels.
- The value is suitable for monitoring trends, relative loudness, and threshold-based alerting.

---

## Current Parameters / 現在の主なパラメータ

- サンプリング周波数: `32000 Hz`
- 集約窓長: `3000 ms`
- サンプル間隔: `100 ms`
- 窓出力: `90パーセンタイル`
- 初期校正時間: 約 `2秒`
- ピークホールド: `1000 ms`
- 時間重み付け: `FAST`

- Sample rate: `32000 Hz`
- Window length: `3000 ms`
- Sample interval: `100 ms`
- Window output: `90th percentile`
- Initial calibration time: about `2 seconds`
- Peak hold: `1000 ms`
- Time weighting: `FAST`

## Image
![Sensor](/docs/esp32s3_sensor_1.jpg)
![Sensor](/docs/esp32s3_sensor_2.jpg)
