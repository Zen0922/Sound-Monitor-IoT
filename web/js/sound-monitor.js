(function () {
  const ENDPOINT = "./api/sensors/get-current.php";
  const REFRESH_MS = 3000;

  const SET_NOISY_THRESHOLD_ENDPOINT = "./api/sensors/set-threshold.php";
  const GET_SETTING_ENDPOINT = "./api/settings/get.php";
  const GET_DETECTED_SENSOR_DEVICE_ENDPOINT = "./api/detected-devices/list.php";
  const REGISTER_SENSOR_DEVICE_ENDPOINT = "./api/sensors/register.php";
  const IGNORE_DETECTED_SENSOR_DEVICE_ENDPOINT = "./api/detected-devices/ignore.php";
  const SETTINGS_URL = "./sensor-settings.php";

  const SERVER_LOCAL_HOST =
    (window.SOUND_MONITOR_CONFIG && window.SOUND_MONITOR_CONFIG.SERVER_LOCAL_HOST)
      ? window.SOUND_MONITOR_CONFIG.SERVER_LOCAL_HOST
      : "";
  const SERVER_IP =
    (window.SOUND_MONITOR_CONFIG && window.SOUND_MONITOR_CONFIG.SERVER_IP)
      ? window.SOUND_MONITOR_CONFIG.SERVER_IP
      : "";
  const SETTINGS_OVERLAY_TIMEOUT_MS = 60000;

  const MIN = 0;
  const MAX = 30;

  const STALE_MS = 10 * 60 * 1000;
  const STALE_BLINK_PERIOD_MS = 5000;
  const ANIM_MS = 2000;

  const canvas = document.getElementById("viz");
  const ctx = canvas.getContext("2d");

  const btnNoisy = document.getElementById("btnNoisy");
  const btnSettings = document.getElementById("btnSettings");
  const btnPair = document.getElementById("btnPair");
  const toast = document.getElementById("toast");
  const settingsOverlay = document.getElementById("settingsOverlay");
  const settingsQrImage = document.getElementById("settingsQrImage");
  const settingsUrlLocal = document.getElementById("settingsUrlLocal");
  const settingsUrlIp = document.getElementById("settingsUrlIp");
  const pairingOverlay = document.getElementById("pairingOverlay");
  const pairingList = document.getElementById("pairingList");
  const btnPairingClose = document.getElementById("btnPairingClose");

  const W = canvas.width;
  const H = canvas.height;
  const cx = W / 2;
  const cy = H / 2;

  const OUTER_R = 400;
  const INNER_R = 250;

  const currentValues = new Map();
  const targetValues = new Map();
  const staleFlags = new Map();

  let latestRows = [];
  let deviceOrder = [];
  let hasUnpairedDevices = false;
  let detectedDevices = [];
  let isRegisteringDevice = false;
  let ringStartAngleDeg = 0;
  let settingsOverlayTimer = null;

  let animStart = 0;
  let animRunning = false;

  function clamp(v, a, b) {
    return Math.max(a, Math.min(b, v));
  }

  function lerp(a, b, t) {
    return a + (b - a) * t;
  }

  function easeInOutCubic(t) {
    return t < 0.5
      ? 4 * t * t * t
      : 1 - Math.pow(-2 * t + 2, 3) / 2;
  }

  function degToRad(deg) {
    return deg * Math.PI / 180;
  }

  function normalizeAngleDeg(deg) {
    let v = Number(deg);
    if (!Number.isFinite(v)) return 0;
    v = v % 360;
    if (v < 0) v += 360;
    return v;
  }

  function showToast(message, ms = 1800) {
    toast.textContent = message;
    toast.classList.add("show");
    clearTimeout(showToast._timer);
    showToast._timer = setTimeout(() => {
      toast.classList.remove("show");
    }, ms);
  }

  async function fetchJson(url, options = {}) {
    const res = await fetch(url, options);

    if (!res.ok) {
      throw new Error(`HTTP ${res.status}`);
    }

    const json = await res.json();

    if (!json || json.ok !== true) {
      throw new Error(json?.message || "API呼び出しに失敗しました");
    }

    return json;
  }

  function normValue(v) {
    const vv = clamp(v, MIN, MAX);
    return clamp((vv - MIN) / (MAX - MIN), 0, 1);
  }

  function colorFromT(t) {
    t = clamp(t, 0, 1);
    const stops = [
      { t: 0.00, c: [0, 110, 255] },
      { t: 0.33, c: [0, 220, 120] },
      { t: 0.66, c: [255, 210, 0] },
      { t: 1.00, c: [255, 60, 60] },
    ];

    let i = 0;
    while (i < stops.length - 2 && t > stops[i + 1].t) i++;

    const a = stops[i];
    const b = stops[i + 1];
    const u = (t - a.t) / (b.t - a.t);

    const r = Math.round(a.c[0] + (b.c[0] - a.c[0]) * u);
    const g = Math.round(a.c[1] + (b.c[1] - a.c[1]) * u);
    const bb = Math.round(a.c[2] + (b.c[2] - a.c[2]) * u);

    return `rgb(${r},${g},${bb})`;
  }

  function clear() {
    ctx.clearRect(0, 0, W, H);
    ctx.fillStyle = "#000";
    ctx.fillRect(0, 0, W, H);
  }

  function parseMysqlDatetime(s) {
    if (typeof s !== "string") return NaN;
    return new Date(s.replace(" ", "T")).getTime();
  }

  function getRowSortValue(row) {
    const raw = row?.display_order;
    if (raw === null || raw === undefined || raw === "") {
      return Number.MAX_SAFE_INTEGER;
    }
    const n = Number(raw);
    return Number.isFinite(n) ? n : Number.MAX_SAFE_INTEGER;
  }

  function sortRowsForDisplay(rows) {
    rows.sort((a, b) => {
      const da = getRowSortValue(a);
      const db = getRowSortValue(b);
      if (da !== db) return da - db;

      const ida = Number(a?.device_id ?? Number.MAX_SAFE_INTEGER);
      const idb = Number(b?.device_id ?? Number.MAX_SAFE_INTEGER);
      return ida - idb;
    });
  }

  function detectUnpairedState(json, rows) {
    if (json && json.has_pending === true) return true;
    if (json && Number(json.pending_count ?? 0) > 0) return true;

    if (json && json.has_unpaired === true) return true;
    if (json && Number(json.unpaired_count ?? 0) > 0) return true;
    if (json && Number(json.not_paired_count ?? 0) > 0) return true;

    return Array.isArray(rows) && rows.length > 0;
  }

  function applyPairAlertState() {
    btnPair.classList.toggle("is-alert", hasUnpairedDevices);
  }

  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function formatDetectedDeviceName(row) {
    const name = String(row?.device_bt_name ?? "").trim();
    return name || "名称未取得の機器";
  }

  function formatDetectedDeviceTime(value) {
    const ts = parseMysqlDatetime(String(value ?? ""));
    if (!Number.isFinite(ts)) {
      return String(value ?? "").trim() || "-";
    }

    return new Date(ts).toLocaleString("ja-JP", {
      year: "numeric",
      month: "2-digit",
      day: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit"
    });
  }

  function setDetectedDevices(rows) {
    detectedDevices = Array.isArray(rows) ? rows.slice() : [];
    hasUnpairedDevices = detectedDevices.length > 0;
    applyPairAlertState();
    renderPairingList();
  }

  function renderPairingList() {
    if (!pairingList) return;

    if (!Array.isArray(detectedDevices) || detectedDevices.length === 0) {
      pairingList.innerHTML = '<div class="pairing-empty">新しく検出された未登録機器はありません</div>';
      return;
    }

    pairingList.innerHTML = detectedDevices.map((row, index) => {
      const addr = String(row?.device_bt_addr ?? "").trim();
      const name = escapeHtml(formatDetectedDeviceName(row));
      const safeAddr = escapeHtml(addr);
      const firstDetected = escapeHtml(formatDetectedDeviceTime(row?.detected_first_time));

      return `
        <div class="pairing-item">
          <div class="pairing-main">
            <p class="pairing-name">${name}</p>
            <p class="pairing-meta">BTアドレス: ${safeAddr}</p>
            <p class="pairing-meta">初回検出: ${firstDetected}</p>
          </div>
          <div class="pairing-actions">
            <button class="pairing-register" type="button" data-index="${index}" data-bt-addr="${safeAddr}">登録</button>
            <button class="pairing-ignore" type="button" data-index="${index}" data-bt-addr="${safeAddr}">登録しない</button>
          </div>
        </div>
      `;
    }).join("");
  }

  function setTargetsFromData(rows) {
    const sortedRows = [...rows];
    sortRowsForDisplay(sortedRows);

    latestRows = sortedRows;
    deviceOrder = sortedRows.map((d) => Number(d.device_id));

    const now = Date.now();
    const activeIds = new Set();

    for (const d of sortedRows) {
      const id = Number(d.device_id);
      activeIds.add(id);

      const v = Number(d.measured_value ?? 0);
      const safeValue = Number.isFinite(v) ? v : 0;
      const nt = normValue(safeValue);

      const ts = parseMysqlDatetime(d.max_recorded_datetime);
      const isStale = !Number.isFinite(ts) || (now - ts > STALE_MS);

      staleFlags.set(id, isStale);

      if (!currentValues.has(id)) {
        currentValues.set(id, nt);
      }
      targetValues.set(id, nt);
    }

    for (const id of Array.from(currentValues.keys())) {
      if (!activeIds.has(id)) {
        currentValues.delete(id);
        targetValues.delete(id);
        staleFlags.delete(id);
      }
    }

    animStart = performance.now();
    animRunning = true;
    requestAnimationFrame(tickValueAnim);
  }

  function tickValueAnim(now) {
    if (!animRunning) return;

    const t = clamp((now - animStart) / ANIM_MS, 0, 1);
    const e = easeInOutCubic(t);

    for (const id of deviceOrder) {
      const c = currentValues.get(id) ?? 0;
      const tar = targetValues.get(id) ?? 0;
      currentValues.set(id, lerp(c, tar, e));
    }

    if (t < 1) {
      requestAnimationFrame(tickValueAnim);
    } else {
      animRunning = false;
      for (const id of deviceOrder) {
        currentValues.set(id, targetValues.get(id));
      }
    }
  }

  function getLabelFontSize(label, segAngle) {
    const baseByCount = Math.max(12, Math.min(22, Math.floor(110 / Math.max(1, latestRows.length)) + 8));
    let size = baseByCount;

    if (label.length >= 14) size -= 4;
    else if (label.length >= 10) size -= 2;

    const arcWidthAtTextRadius = (INNER_R + 28) * segAngle;
    if (arcWidthAtTextRadius < 90) size = Math.min(size, 14);
    if (arcWidthAtTextRadius < 70) size = Math.min(size, 12);

    return Math.max(10, size);
  }

  function drawDeviceLabels() {
    const N = latestRows.length;
    if (!N) return;

    const base = -Math.PI / 2 + degToRad(ringStartAngleDeg);
    const segAngle = (Math.PI * 2) / N;
    const textRadius = INNER_R + 28;

    for (let i = 0; i < N; i++) {
      const row = latestRows[i];
      if (!row) continue;

      const label = String(row.device_memo_name ?? "").trim();
      if (!label) continue;

      const a0 = base + i * segAngle;
      const a1 = a0 + segAngle;
      const mid = (a0 + a1) / 2;

      const tx = cx + Math.cos(mid) * textRadius;
      const ty = cy + Math.sin(mid) * textRadius;

      const fontSize = getLabelFontSize(label, segAngle);
      const maxWidth = Math.max(36, (INNER_R * segAngle) - 14);

      ctx.save();
      ctx.translate(tx, ty);
      ctx.rotate(mid + Math.PI / 2);
      ctx.fillStyle = "#ffffff";
      ctx.textAlign = "center";
      ctx.textBaseline = "middle";
      ctx.font = `700 ${fontSize}px system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans JP", sans-serif`;
      ctx.shadowColor = "rgba(0, 0, 0, 0.65)";
      ctx.shadowBlur = 4;
      ctx.lineJoin = "round";
      ctx.strokeStyle = "rgba(0,0,0,0.45)";
      ctx.lineWidth = 3;
      ctx.strokeText(label, 0, 0, maxWidth);
      ctx.fillText(label, 0, 0, maxWidth);
      ctx.restore();
    }
  }

  function renderLoop(now) {
    draw(now);
    requestAnimationFrame(renderLoop);
  }

  function draw(nowPerf) {
    clear();

    const N = latestRows.length;
    if (!N) return;

    const base = -Math.PI / 2 + degToRad(ringStartAngleDeg);
    const segAngle = (Math.PI * 2) / N;

    const phase = (nowPerf % STALE_BLINK_PERIOD_MS) / STALE_BLINK_PERIOD_MS;
    const blink = 0.5 + 0.5 * Math.sin(phase * Math.PI * 2);
    const blinkFactor = 0.35 + 0.65 * blink;

    for (let i = 0; i < N; i++) {
      const row = latestRows[i];
      const id = Number(row.device_id);
      const valueT = currentValues.get(id) ?? 0;

      const a0 = base + i * segAngle;
      const a1 = a0 + segAngle;

      let fillColor;
      if (staleFlags.get(id)) {
        const g = Math.round(140 * blinkFactor);
        fillColor = `rgb(${g},${g},${g})`;
      } else {
        fillColor = colorFromT(valueT);
      }

      ctx.save();
      ctx.beginPath();
      ctx.arc(cx, cy, OUTER_R, a0, a1, false);
      ctx.arc(cx, cy, INNER_R, a1, a0, true);
      ctx.closePath();
      ctx.fillStyle = fillColor;
      ctx.fill();
      ctx.restore();
    }

    drawDeviceLabels();

    ctx.save();
    ctx.strokeStyle = "#000";
    ctx.lineWidth = 5;

    for (let i = 0; i < N; i++) {
      const a = base + i * segAngle;
      const x0 = cx + Math.cos(a) * INNER_R;
      const y0 = cy + Math.sin(a) * INNER_R;
      const x1 = cx + Math.cos(a) * OUTER_R;
      const y1 = cy + Math.sin(a) * OUTER_R;

      ctx.beginPath();
      ctx.moveTo(x0, y0);
      ctx.lineTo(x1, y1);
      ctx.stroke();
    }

    ctx.beginPath();
    ctx.arc(cx, cy, OUTER_R - 2.5, 0, Math.PI * 2);
    ctx.stroke();

    ctx.beginPath();
    ctx.arc(cx, cy, INNER_R + 2.5, 0, Math.PI * 2);
    ctx.stroke();
    ctx.restore();
  }

  function stripPort(host) {
    return String(host || "").replace(/:\d+$/, "");
  }

  function joinUrl(originLike, path) {
    return String(originLike).replace(/\/$/, "") + path;
  }

  function getCurrentDirectoryPath() {
    const pathname = String(location.pathname || "/");
    const normalized = pathname.endsWith("/") ? pathname : pathname.replace(/\/[^/]*$/, "/");
    return normalized || "/";
  }

  function resolveSettingsPath() {
    if (SETTINGS_URL.startsWith("/")) {
      return SETTINGS_URL;
    }

    const baseDir = getCurrentDirectoryPath();
    const relative = SETTINGS_URL.replace(/^\.\//, "");
    return baseDir + relative;
  }

  function resolveLocalSettingsUrl() {
    const protocol = location.protocol || "http:";
    const pathname = resolveSettingsPath();
    const currentHost = stripPort(location.host || location.hostname || "");

    let localHost = stripPort(SERVER_LOCAL_HOST || currentHost || "raspberrypi.local");
    if (!localHost) {
      localHost = "raspberrypi.local";
    }
    if (!/\.local$/i.test(localHost)) {
      localHost += ".local";
    }

    return joinUrl(`${protocol}//${localHost}`, pathname);
  }

  function resolveIpSettingsUrl() {
    const protocol = location.protocol || "http:";
    const pathname = resolveSettingsPath();
    const fallbackHost = stripPort(location.hostname || "");
    const ip = SERVER_IP || (/^\d+\.\d+\.\d+\.\d+$/.test(fallbackHost) ? fallbackHost : "");

    return ip ? joinUrl(`${protocol}//${ip}`, pathname) : "";
  }

  function setOverlayUrlText(el, value) {
    el.textContent = value || "取得できませんでした";
    el.title = value || "";
  }

  function showSettingsOverlay() {
    const localUrl = resolveLocalSettingsUrl();
    const ipUrl = resolveIpSettingsUrl();
    const qrUrl = ipUrl || localUrl;

    settingsQrImage.src = `?qr=1&text=${encodeURIComponent(qrUrl)}`;
    setOverlayUrlText(settingsUrlLocal, localUrl);
    setOverlayUrlText(settingsUrlIp, ipUrl);

    settingsOverlay.classList.add("show");
    settingsOverlay.setAttribute("aria-hidden", "false");

    clearTimeout(settingsOverlayTimer);
    settingsOverlayTimer = setTimeout(hideSettingsOverlay, SETTINGS_OVERLAY_TIMEOUT_MS);
  }

  function hideSettingsOverlay() {
    clearTimeout(settingsOverlayTimer);
    settingsOverlayTimer = null;
    settingsOverlay.classList.remove("show");
    settingsOverlay.setAttribute("aria-hidden", "true");
    settingsQrImage.removeAttribute("src");
  }

  function showPairingOverlay() {
    pairingOverlay.classList.add("show");
    pairingOverlay.setAttribute("aria-hidden", "false");
    btnPairingClose.focus();
  }

  function hidePairingOverlay() {
    if (document.activeElement && pairingOverlay.contains(document.activeElement)) {
      btnPair.focus();
    }

    pairingOverlay.classList.remove("show");
    pairingOverlay.setAttribute("aria-hidden", "true");
  }

  async function fetchDetectedDevices() {
    const json = await fetchJson(GET_DETECTED_SENSOR_DEVICE_ENDPOINT, {
      cache: "no-store"
    });

    const rows = Array.isArray(json.data) ? json.data : [];
    hasUnpairedDevices = detectUnpairedState(json, rows);
    setDetectedDevices(rows);
    return rows;
  }

  async function registerDetectedDevice(deviceBtAddr) {
    const addr = String(deviceBtAddr ?? "").trim();
    if (!addr || isRegisteringDevice) return;

    isRegisteringDevice = true;
    btnPair.disabled = true;
    btnPairingClose.disabled = true;
    pairingList.querySelectorAll("button").forEach((button) => {
      button.disabled = true;
    });

    try {
      const json = await fetchJson(REGISTER_SENSOR_DEVICE_ENDPOINT, {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({
          device_bt_addr: addr
        })
      });

      showToast(json.message || "機器を登録しました", 2200);
      await Promise.all([refresh(), fetchDetectedDevices()]);

      if (!detectedDevices.length) {
        hidePairingOverlay();
      } else {
        renderPairingList();
      }
    } catch (e) {
      console.error("機器登録失敗:", e);
      showToast("機器登録に失敗しました", 2200);
      pairingList.querySelectorAll("button").forEach((button) => {
        button.disabled = false;
      });
    } finally {
      isRegisteringDevice = false;
      btnPair.disabled = false;
      btnPairingClose.disabled = false;
    }
  }

  async function ignoreDetectedDevice(deviceBtAddr) {
    const addr = String(deviceBtAddr ?? "").trim();
    if (!addr || isRegisteringDevice) return;

    isRegisteringDevice = true;
    btnPair.disabled = true;
    btnPairingClose.disabled = true;
    pairingList.querySelectorAll("button").forEach((button) => {
      button.disabled = true;
    });

    try {
      const json = await fetchJson(IGNORE_DETECTED_SENSOR_DEVICE_ENDPOINT, {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({
          device_bt_addr: addr
        })
      });

      showToast(json.message || "機器を登録しない対象に設定しました", 2200);
      await fetchDetectedDevices();

      if (!detectedDevices.length) {
        hidePairingOverlay();
      } else {
        renderPairingList();
      }
    } catch (e) {
      console.error("機器無視設定失敗:", e);
      showToast("機器を無視に設定できませんでした", 2200);
      pairingList.querySelectorAll("button").forEach((button) => {
        button.disabled = false;
      });
    } finally {
      isRegisteringDevice = false;
      btnPair.disabled = false;
      btnPairingClose.disabled = false;
    }
  }

  async function loadRingStartAngle() {
    try {
      const json = await fetchJson(`${GET_SETTING_ENDPOINT}?type=ring_start_angle_deg`, {
        cache: "no-store"
      });

      if (!json.found) return;

      const deg = Number(json.setting_value);
      if (Number.isFinite(deg)) {
        ringStartAngleDeg = normalizeAngleDeg(deg);
      }
    } catch (e) {
      console.error("リング開始角度の取得失敗:", e);
    }
  }

  async function fetchSensorValues() {
    const json = await fetchJson(ENDPOINT, {
      cache: "no-store"
    });

    if (!Array.isArray(json.data)) {
      throw new Error("JSONのdataが配列ではありません");
    }

    return json.data;
  }

  async function refresh() {
    try {
      const rows = await fetchSensorValues();
      setTargetsFromData(rows);
    } catch (e) {
      console.error("取得失敗:", e);
    }
  }

  async function submitNoisyThreshold() {
    btnNoisy.disabled = true;

    try {
      const json = await fetchJson(SET_NOISY_THRESHOLD_ENDPOINT, {
        method: "POST",
        headers: {
          "Content-Type": "application/json"
        },
        body: JSON.stringify({
          source: "sound-monitor",
          requested_at: new Date().toISOString(),
          lookback_seconds: 30,
          method: "p95"
        })
      });

      if (Number(json.saved_count ?? 0) > 0) {
        showToast(`基準音レベルを保存しました (${json.saved_count}台)`);
      } else {
        showToast(json.message || "過去30秒のデータがありませんでした");
      }
    } catch (e) {
      console.error("基準音レベル保存失敗:", e);
      showToast("基準音レベル保存に失敗しました");
    } finally {
      btnNoisy.disabled = false;
    }
  }

  btnNoisy.addEventListener("click", submitNoisyThreshold);

  btnSettings.addEventListener("click", (event) => {
    event.preventDefault();
    showSettingsOverlay();
  });

  btnPair.addEventListener("click", async () => {
    try {
      await fetchDetectedDevices();
    } catch (e) {
      console.error("検出機器一覧取得失敗:", e);
      showToast("検出機器一覧の取得に失敗しました", 2200);
      return;
    }

    showPairingOverlay();
  });

  settingsOverlay.addEventListener("click", hideSettingsOverlay);

  pairingOverlay.addEventListener("click", (event) => {
    if (event.target === pairingOverlay) {
      hidePairingOverlay();
    }
  });

  document.querySelector(".pairing-dialog").addEventListener("click", (event) => {
    event.stopPropagation();
  });

  btnPairingClose.addEventListener("click", hidePairingOverlay);

  pairingList.addEventListener("click", (event) => {
    const registerButton = event.target.closest(".pairing-register");
    if (registerButton) {
      const deviceBtAddr = String(registerButton.dataset.btAddr || "").trim();
      registerDetectedDevice(deviceBtAddr);
      return;
    }

    const ignoreButton = event.target.closest(".pairing-ignore");
    if (ignoreButton) {
      const deviceBtAddr = String(ignoreButton.dataset.btAddr || "").trim();
      ignoreDetectedDevice(deviceBtAddr);
    }
  });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      hideSettingsOverlay();
      hidePairingOverlay();
    }
  });

  async function init() {
    await loadRingStartAngle();
    requestAnimationFrame(renderLoop);
    await Promise.all([refresh(), fetchDetectedDevices()]);

    setInterval(refresh, REFRESH_MS);

    setInterval(() => {
      fetchDetectedDevices().catch((e) => {
        console.error("検出機器一覧自動更新失敗:", e);
      });
    }, REFRESH_MS);
  }

  init();

  (function watchSourceAndReload() {
    const sse = new EventSource("./app/dev/watch-build.php");

    sse.addEventListener("changed", () => {
      location.reload();
    });

    sse.onerror = (e) => {
      console.log("SSE reconnecting...", e);
    };
  })();
})();