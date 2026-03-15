const API_URL = "./api/sensors/get-history.php";
const JST_SUFFIX = "+09:00";

const elHours = document.getElementById("hours");
const elDeviceSelect = document.getElementById("deviceSelect");
const elDeviceInput = document.getElementById("deviceInput");
const elCompact = document.getElementById("compact");
const elAuto = document.getElementById("auto");
const elIntervalSec = document.getElementById("intervalSec");
const elMode = document.getElementById("mode");
const elValueTypeMode = document.getElementById("valueTypeMode");
const elSmoothSec = document.getElementById("smoothSec");
const elWarnTh = document.getElementById("warnTh");
const elCritTh = document.getElementById("critTh");
const elReload = document.getElementById("reload");
const elResetZoom = document.getElementById("resetZoom");
const elStatus = document.getElementById("status");
const elLastUpdated = document.getElementById("lastUpdated");
const elLatestTime = document.getElementById("latestTime");
const elChartTitle = document.getElementById("chartTitle");
const elChartSubtitle = document.getElementById("chartSubtitle");
const elValueTypePill = document.getElementById("valueTypePill");

const sidebarCard = document.getElementById("sidebarCard");
const chartCard = document.getElementById("chartCard");
const ringCard = document.getElementById("ringCard");
const latestCard = document.getElementById("latestCard");

const latestTableBody = document.querySelector("#latestTable tbody");
const ringCanvas = document.getElementById("ringCanvas");

const ctx = document.getElementById("chart").getContext("2d");
let chart = null;
let timer = null;
let lastFingerprint = "";

function setStatus(msg) {
  elStatus.textContent = msg;
}

async function fetchJson(url, options = {}) {
  const res = await fetch(url, options);
  if (!res.ok) {
    throw new Error(`HTTP ${res.status} ${res.statusText}`);
  }

  const json = await res.json();
  if (!json || json.ok !== true) {
    throw new Error(json?.message || "API呼び出しに失敗しました");
  }

  return json;
}

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#39;"
  }[c]));
}

function getThresholds() {
  const warn = parseFloat(elWarnTh.value);
  const crit = parseFloat(elCritTh.value);
  return {
    warn: Number.isFinite(warn) ? warn : 20,
    crit: Number.isFinite(crit) ? crit : 30
  };
}

function getLevelState(value) {
  const { warn, crit } = getThresholds();
  if (!Number.isFinite(value)) return "ok";
  if (value >= crit) return "crit";
  if (value >= warn) return "warn";
  return "ok";
}

function colorForKey(deviceId, valueType = "level") {
  const n = Number(deviceId) || 0;
  const baseHue = (n * 47) % 360;
  if (valueType === "baseline") {
    return `hsl(${baseHue} 35% 45%)`;
  }
  return `hsl(${baseHue} 70% 45%)`;
}

function severityColor(state) {
  if (state === "crit") return "#dc2626";
  if (state === "warn") return "#f59e0b";
  return "#10b981";
}

function displayName(device) {
  const memo = (device.device_memo_name || "").trim();
  const btName = (device.device_bt_name || "").trim();
  return memo !== "" ? memo : btName;
}

function getDeviceIdFilter() {
  const inputVal = (elDeviceInput.value || "").trim();
  if (inputVal !== "") return inputVal;
  if (elMode.value === "one") {
    const sel = (elDeviceSelect.value || "").trim();
    if (sel !== "") return sel;
  }
  return "";
}

function getValueTypeFilter() {
  const mode = elValueTypeMode.value;
  return (mode === "all") ? "" : mode;
}

function buildUrl() {
  const u = new URL(API_URL, window.location.href);
  u.searchParams.set("hours", elHours.value);
  u.searchParams.set("compact", elCompact.value);

  const did = getDeviceIdFilter();
  if (did !== "") u.searchParams.set("device_id", did);
  else u.searchParams.delete("device_id");

  const valueType = getValueTypeFilter();
  if (valueType !== "") u.searchParams.set("value_type", valueType);
  else u.searchParams.delete("value_type");

  return u.toString();
}

function toMsFromMeasuredDatetimeStr(s) {
  if (typeof s !== "string" || s.trim() === "") return NaN;
  const iso = s.replace(" ", "T") + JST_SUFFIX;
  return Date.parse(iso);
}

function smoothPoints(points, windowSec) {
  const w = windowSec * 1000;
  if (!windowSec || windowSec <= 0 || points.length < 3) return points;

  const out = [];
  let left = 0;
  let sum = 0;
  let cnt = 0;

  for (let i = 0; i < points.length; i++) {
    const xi = points[i].x;
    const yi = points[i].y;

    if (Number.isFinite(yi)) {
      sum += yi;
      cnt += 1;
    }

    while (left <= i && (xi - points[left].x) > w) {
      const yl = points[left].y;
      if (Number.isFinite(yl)) {
        sum -= yl;
        cnt -= 1;
      }
      left++;
    }

    out.push({ x: xi, y: (cnt > 0) ? (sum / cnt) : null });
  }

  return out;
}

function normalizeToDatasets(json) {
  const compact = !!json.compact;
  const devices = Array.isArray(json.devices) ? json.devices : [];
  const datasets = [];
  const smoothSec = parseInt(elSmoothSec.value, 10) || 0;

  for (const d of devices) {
    const did = d.device_id;
    const vals = Array.isArray(d.values) ? d.values : [];
    const groups = new Map();

    for (const v of vals) {
      const valueType = compact ? (v.y || "level") : (v.value_type || "level");
      if (valueType === "aux1" || valueType === "aux2") continue;

      let tMs;
      let y;

      if (compact) {
        tMs = (v.t ?? 0) * 1000;
        y = parseFloat(v.v);
      } else {
        tMs = toMsFromMeasuredDatetimeStr(v.measured_datetime);
        y = parseFloat(v.measured_value);
      }

      if (!Number.isFinite(tMs)) continue;

      if (!groups.has(valueType)) groups.set(valueType, []);
      groups.get(valueType).push({
        x: tMs,
        y: Number.isFinite(y) ? y : null
      });
    }

    for (const [valueType, pointsRaw] of groups.entries()) {
      const points = smoothPoints(pointsRaw, smoothSec);
      const name = displayName(d);
      const stroke = colorForKey(did, valueType);

      datasets.push({
        label: `${name} [${valueType}]`,
        data: points,
        spanGaps: true,
        pointRadius: 0,
        borderWidth: valueType === "baseline" ? 1.5 : 2.5,
        tension: 0.15,
        borderColor: stroke,
        borderDash: valueType === "baseline" ? [6, 4] : [],
        yAxisID: valueType === "baseline" ? "yBaseline" : "yLevel",
        meta: {
          device_id: did,
          value_type: valueType,
          title: name,
          bt_name: d.device_bt_name || "",
          bt_addr: d.device_bt_addr || ""
        }
      });
    }
  }

  const { warn, crit } = getThresholds();
  const selectedMode = elValueTypeMode.value;
  if (selectedMode === "all" || selectedMode === "level") {
    datasets.push({
      label: "warn threshold",
      data: [],
      borderColor: "#f59e0b",
      borderWidth: 1,
      borderDash: [4, 4],
      pointRadius: 0,
      yAxisID: "yLevel",
      meta: { helper: true },
      parsing: false
    });
    datasets.push({
      label: "crit threshold",
      data: [],
      borderColor: "#dc2626",
      borderWidth: 1,
      borderDash: [6, 6],
      pointRadius: 0,
      yAxisID: "yLevel",
      meta: { helper: true },
      parsing: false
    });
  }

  return { datasets, warn, crit };
}

function extractXRange(datasets) {
  let minX = Infinity;
  let maxX = -Infinity;

  for (const ds of datasets) {
    if (ds.meta?.helper) continue;

    for (const p of ds.data || []) {
      if (!Number.isFinite(p.x)) continue;
      if (p.x < minX) minX = p.x;
      if (p.x > maxX) maxX = p.x;
    }
  }

  if (!Number.isFinite(minX) || !Number.isFinite(maxX)) return null;
  return { minX, maxX };
}

function applyThresholdLines(datasets, warn, crit) {
  const range = extractXRange(datasets);
  if (!range) return datasets;

  for (const ds of datasets) {
    if (ds.label === "warn threshold") {
      ds.data = [
        { x: range.minX, y: warn },
        { x: range.maxX, y: warn }
      ];
    } else if (ds.label === "crit threshold") {
      ds.data = [
        { x: range.minX, y: crit },
        { x: range.maxX, y: crit }
      ];
    }
  }

  return datasets;
}

function renderChart(datasets, warn, crit) {
  datasets = applyThresholdLines(datasets, warn, crit);

  if (chart) chart.destroy();

  chart = new Chart(ctx, {
    type: "line",
    data: { datasets },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      parsing: false,
      animation: false,
      transitions: {
        active: { animation: { duration: 0 } },
        show: { animation: { duration: 0 } },
        hide: { animation: { duration: 0 } }
      },
      plugins: {
        legend: {
          display: true,
          labels: {
            filter: (item, data) => {
              const ds = data.datasets[item.datasetIndex];
              return !(ds.meta && ds.meta.helper);
            }
          }
        },
        tooltip: {
          intersect: false,
          mode: "index",
          callbacks: {
            label: (c) => {
              const ds = c.dataset || {};
              if (ds.meta?.helper) return `${ds.label}: ${c.raw?.y}`;
              const vt = ds.meta?.value_type || "";
              return `${ds.label}: ${c.raw?.y} (${vt})`;
            },
            afterLabel: (c) => {
              const m = c.dataset.meta || {};
              if (m.helper) return [];
              return [`BT Name: ${m.bt_name || "-"}`, `BT Addr: ${m.bt_addr || "-"}`];
            }
          }
        },
        decimation: {
          enabled: true,
          algorithm: "lttb",
          samples: 1500
        },
        zoom: {
          limits: {
            x: { min: "original", max: "original" },
            yLevel: { min: "original", max: "original" },
            yBaseline: { min: "original", max: "original" }
          },
          pan: {
            enabled: true,
            mode: "x"
          },
          zoom: {
            wheel: { enabled: true },
            pinch: { enabled: true },
            drag: { enabled: false },
            mode: "x"
          }
        }
      },
      interaction: {
        intersect: false,
        mode: "index"
      },
      scales: {
        x: {
          type: "time",
          adapters: {
            date: { zone: "Asia/Tokyo" }
          },
          time: {
            tooltipFormat: "yyyy/MM/dd HH:mm:ss",
            displayFormats: {
              minute: "HH:mm",
              hour: "MM/dd HH:mm"
            }
          },
          ticks: { maxRotation: 0 }
        },
        yLevel: {
          type: "linear",
          position: "left",
          min: 0,
          title: {
            display: true,
            text: "level"
          }
        },
        yBaseline: {
          type: "linear",
          position: "right",
          min: 0,
          grid: {
            drawOnChartArea: false
          },
          title: {
            display: true,
            text: "baseline"
          }
        }
      }
    }
  });
}

function extractLatestRows(json) {
  const compact = !!json.compact;
  const devices = Array.isArray(json.devices) ? json.devices : [];
  const rows = [];

  for (const d of devices) {
    const vals = Array.isArray(d.values) ? d.values : [];
    if (vals.length === 0) continue;

    let latestLevel = null;
    let latestBaseline = null;
    let latestTime = 0;
    let latestTimeStr = "";

    for (const v of vals) {
      const valueType = compact ? (v.y || "level") : (v.value_type || "level");
      if (valueType === "aux1" || valueType === "aux2") continue;

      let tMs;
      let timeStr;
      let value;
      let valueStr;

      if (compact) {
        tMs = (v.t ?? 0) * 1000;
        timeStr = new Date(tMs).toLocaleString("ja-JP", { timeZone: "Asia/Tokyo" });
        value = parseFloat(v.v);
        valueStr = v.v;
      } else {
        tMs = toMsFromMeasuredDatetimeStr(v.measured_datetime);
        timeStr = v.measured_datetime;
        value = parseFloat(v.measured_value);
        valueStr = v.measured_value;
      }

      if (tMs > latestTime) {
        latestTime = tMs;
        latestTimeStr = timeStr;
      }

      if (valueType === "level") {
        if (!latestLevel || tMs >= latestLevel.tMs) {
          latestLevel = { tMs, value, valueStr };
        }
      } else if (valueType === "baseline") {
        if (!latestBaseline || tMs >= latestBaseline.tMs) {
          latestBaseline = { tMs, value, valueStr };
        }
      }
    }

    rows.push({
      device_id: d.device_id,
      display_name: displayName(d),
      bt_addr: d.device_bt_addr || "",
      measured_datetime: latestTimeStr,
      latest_level: latestLevel,
      latest_baseline: latestBaseline,
      level_value: latestLevel?.value ?? NaN,
      level_value_str: latestLevel?.valueStr ?? "-",
      baseline_value_str: latestBaseline?.valueStr ?? "-"
    });
  }

  rows.sort((a, b) => Number(a.device_id) - Number(b.device_id));
  return rows;
}

function renderLatestTable(rows) {
  latestTableBody.innerHTML = "";
  if (rows.length === 0) {
    latestTableBody.innerHTML = `<tr><td colspan="6" class="emptyCell">データ無し</td></tr>`;
    return;
  }

  for (const r of rows) {
    const state = getLevelState(r.level_value);
    const badgeClass = `badge ${state}`;
    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>
        <div class="devTitle">${escapeHtml(r.display_name || `device ${r.device_id}`)}</div>
        <div class="devMeta">ID: ${escapeHtml(String(r.device_id))}</div>
      </td>
      <td>${escapeHtml(r.bt_addr || "-")}</td>
      <td>${escapeHtml(r.measured_datetime || "-")}</td>
      <td class="valueCell">${escapeHtml(String(r.level_value_str))}</td>
      <td class="valueCell">${escapeHtml(String(r.baseline_value_str))}</td>
      <td><span class="${badgeClass}">${state.toUpperCase()}</span></td>
    `;
    latestTableBody.appendChild(tr);
  }
}

function updateDeviceSelectFromJson(json) {
  const devices = Array.isArray(json.devices) ? json.devices : [];
  const currentValue = (elDeviceSelect.value || "").trim();

  elDeviceSelect.innerHTML = "";
  const allOpt = document.createElement("option");
  allOpt.value = "";
  allOpt.textContent = "全て";
  elDeviceSelect.appendChild(allOpt);

  const sortedDevices = [...devices].sort((a, b) => {
    const ao = Number(a.display_order ?? Number.MAX_SAFE_INTEGER);
    const bo = Number(b.display_order ?? Number.MAX_SAFE_INTEGER);
    if (ao !== bo) return ao - bo;
    return Number(a.device_id ?? Number.MAX_SAFE_INTEGER) - Number(b.device_id ?? Number.MAX_SAFE_INTEGER);
  });

  for (const d of sortedDevices) {
    const id = String(d.device_id);
    const opt = document.createElement("option");
    opt.value = id;
    opt.textContent = `${displayName(d)} (ID:${d.device_id})`;
    elDeviceSelect.appendChild(opt);
  }

  const exists = [...elDeviceSelect.options].some(o => o.value === currentValue);
  elDeviceSelect.value = exists ? currentValue : "";
}

function renderRing(rows) {
  const ringRows = rows.filter(r => Number.isFinite(r.level_value));
  const c = ringCanvas;
  const dpr = window.devicePixelRatio || 1;
  const rect = c.getBoundingClientRect();
  const size = Math.min(rect.width, rect.height);

  c.width = Math.floor(size * dpr);
  c.height = Math.floor(size * dpr);

  const g = c.getContext("2d");
  g.setTransform(dpr, 0, 0, dpr, 0, 0);
  g.clearRect(0, 0, size, size);

  const cx = size / 2;
  const cy = size / 2;
  const outerR = size * 0.46;
  const innerR = size * 0.30;

  g.beginPath();
  g.arc(cx, cy, outerR, 0, Math.PI * 2);
  g.arc(cx, cy, innerR, 0, Math.PI * 2, true);
  g.closePath();
  g.fillStyle = "#f9fafb";
  g.fill();
  g.strokeStyle = "#e5e7eb";
  g.lineWidth = 1;
  g.stroke();

  if (ringRows.length === 0) {
    g.fillStyle = "#6b7280";
    g.font = "14px system-ui";
    g.textAlign = "center";
    g.textBaseline = "middle";
    g.fillText("no level", cx, cy);
    return;
  }

  const n = ringRows.length;
  const start = -Math.PI / 2;
  const step = (Math.PI * 2) / n;

  for (let i = 0; i < n; i++) {
    const r = ringRows[i];
    const a0 = start + step * i;
    const a1 = a0 + step;
    const state = getLevelState(r.level_value);

    g.beginPath();
    g.arc(cx, cy, outerR, a0, a1);
    g.arc(cx, cy, innerR, a1, a0, true);
    g.closePath();
    g.fillStyle = severityColor(state);
    g.fill();
    g.strokeStyle = "#ffffff";
    g.lineWidth = 2;
    g.stroke();
  }

  const worst = ringRows.reduce((acc, r) => {
    const s = getLevelState(r.level_value);
    const rank = s === "crit" ? 2 : s === "warn" ? 1 : 0;
    if (!acc || rank > acc.rank) return { r, rank };
    return acc;
  }, null);

  g.fillStyle = "#111827";
  g.font = "12px system-ui";
  g.textAlign = "center";
  g.textBaseline = "middle";
  g.fillText("Worst level", cx, cy - 10);
  g.font = "14px system-ui";
  g.fillText(`${worst.r.display_name}`, cx, cy + 10);
}

function updateHeadings(json) {
  const did = getDeviceIdFilter();
  const modeText = elValueTypeMode.options[elValueTypeMode.selectedIndex].textContent;
  elValueTypePill.textContent = modeText;

  if (did === "") {
    elChartTitle.textContent = "センサー推移";
    elChartSubtitle.textContent = "全device表示";
    return;
  }

  const target = (json.devices || []).find(d => String(d.device_id) === String(did));
  if (!target) {
    elChartTitle.textContent = `device ${did}`;
    elChartSubtitle.textContent = "該当device情報なし";
    return;
  }

  elChartTitle.textContent = displayName(target);
  elChartSubtitle.textContent = `BT Name: ${target.device_bt_name || "-"} / BT Addr: ${target.device_bt_addr || "-"}`;
}

function makeFingerprint(latestRows) {
  return JSON.stringify(
    latestRows.map(r => ({
      id: r.device_id,
      t: r.measured_datetime,
      l: r.level_value_str,
      b: r.baseline_value_str
    }))
  );
}

function flashIfChanged(newFingerprint) {
  if (!lastFingerprint) {
    lastFingerprint = newFingerprint;
    return;
  }
  if (lastFingerprint === newFingerprint) return;

  lastFingerprint = newFingerprint;
  [sidebarCard, chartCard, ringCard, latestCard].forEach(el => {
    el.classList.remove("flash");
    void el.offsetWidth;
    el.classList.add("flash");
    setTimeout(() => el.classList.remove("flash"), 1200);
  });
}

async function loadAndDraw() {
  const url = buildUrl();
  setStatus(`fetch: ${url}`);

  try {
    const json = await fetchJson(url, { cache: "no-store" });

    const deviceCount = Array.isArray(json.devices) ? json.devices.length : 0;
    let pointCount = 0;
    for (const d of (json.devices ?? [])) {
      pointCount += Array.isArray(d.values) ? d.values.length : 0;
    }

    setStatus(`ok\nfrom: ${json.from}\nto: ${json.to}\ndevices: ${deviceCount}\npoints: ${pointCount}`);

    updateDeviceSelectFromJson(json);

    const latestRows = extractLatestRows(json);
    flashIfChanged(makeFingerprint(latestRows));

    updateHeadings(json);
    renderLatestTable(latestRows);
    renderRing(latestRows);

    if (latestRows.length) {
      const latestMs = Math.max(...latestRows.map(r => {
        const t = Date.parse((r.measured_datetime || "").replace(" ", "T") + JST_SUFFIX);
        return Number.isFinite(t) ? t : 0;
      }));
      elLatestTime.textContent = latestMs > 0
        ? new Date(latestMs).toLocaleString("ja-JP", { timeZone: "Asia/Tokyo" })
        : "--";
    } else {
      elLatestTime.textContent = "--";
    }

    const normalized = normalizeToDatasets(json);
    renderChart(normalized.datasets, normalized.warn, normalized.crit);

    elLastUpdated.textContent = new Date().toLocaleString("ja-JP", {
      timeZone: "Asia/Tokyo"
    });

  } catch (e) {
    setStatus("error: " + (e?.message ?? e));
    elLastUpdated.textContent = "--";
    elLatestTime.textContent = "--";
    elChartTitle.textContent = "センサー推移";
    elChartSubtitle.textContent = "--";
    latestTableBody.innerHTML = `<tr><td colspan="6" class="errorCell">エラー</td></tr>`;
    renderRing([]);
    if (chart) chart.destroy();
  }
}

function startAuto() {
  stopAuto();
  if (elAuto.value !== "1") return;

  const sec = parseInt(elIntervalSec.value, 10);
  if (!Number.isFinite(sec) || sec <= 0) return;

  timer = setInterval(loadAndDraw, sec * 1000);
}

function stopAuto() {
  if (timer) {
    clearInterval(timer);
    timer = null;
  }
}

elReload.addEventListener("click", loadAndDraw);
elResetZoom.addEventListener("click", () => chart && chart.resetZoom());
elAuto.addEventListener("change", startAuto);
elIntervalSec.addEventListener("change", startAuto);
elMode.addEventListener("change", loadAndDraw);
elValueTypeMode.addEventListener("change", loadAndDraw);
elDeviceSelect.addEventListener("change", () => {
  if (elMode.value === "one" && (elDeviceInput.value || "").trim() === "") {
    loadAndDraw();
  }
});
elDeviceInput.addEventListener("change", loadAndDraw);
elSmoothSec.addEventListener("change", loadAndDraw);
elWarnTh.addEventListener("change", loadAndDraw);
elCritTh.addEventListener("change", loadAndDraw);
elCompact.addEventListener("change", loadAndDraw);
elHours.addEventListener("change", loadAndDraw);

loadAndDraw();