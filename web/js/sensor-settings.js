(() => {
    const list = document.getElementById('sensorList');
    const form = document.getElementById('settingsForm');
    const saveBtn = document.getElementById('saveBtn');
    const toast = document.getElementById('saveToast');
    const ringStartAngle = document.getElementById('ringStartAngle');
    const angleHelp = document.getElementById('angleHelp');
    const globalErrorBox = document.getElementById('globalErrorBox');

    const GET_SETTING_ENDPOINT = './api/settings/get.php';
    const UPDATE_SETTING_ENDPOINT = './api/settings/update.php';
    const GET_SENSORS_LIST_ENDPOINT = './api/sensors/list.php';
    const GET_SENSOR_VALUE_ENDPOINT = './api/sensors/get-current.php';
    const DELETE_SENSOR_DEVICE_ENDPOINT = './api/sensors/delete.php';
    const UNIGNORE_DETECTED_SENSOR_DEVICE_ENDPOINT = './api/detected-devices/unignore.php';

    function showGlobalError(message) {
        globalErrorBox.textContent = message;
        globalErrorBox.classList.add('show');
    }

    function clearGlobalError() {
        globalErrorBox.textContent = '';
        globalErrorBox.classList.remove('show');
    }

    function normalizeAngleDeg(deg) {
        let v = Number(deg);
        if (!Number.isFinite(v)) return 0;
        v = v % 360;
        if (v < 0) v += 360;
        return Math.round(v * 10) / 10;
    }

    function formatAngle(deg) {
        const v = normalizeAngleDeg(deg);
        return Number.isInteger(v) ? `${v}°` : `${v.toFixed(1)}°`;
    }

    function getSensorCards() {
        return Array.from(list.querySelectorAll('.sensor-card'));
    }

    function updateOrder() {
        const cards = getSensorCards();

        cards.forEach((card, index) => {
            const order = index + 1;
            const orderInput = card.querySelector('.order-input');
            const orderNumber = card.querySelector('.order-number');
            const orderDirectInput = card.querySelector('.order-direct-input');

            if (orderInput) {
                orderInput.value = String(order);
            }

            if (orderNumber) {
                orderNumber.textContent = String(order);
            }

            if (orderDirectInput && document.activeElement !== orderDirectInput) {
                orderDirectInput.value = String(order);
            }
        });

        renderAngleOptions();
    }

    function moveCard(card, direction) {
        if (!card) return;

        if (direction === 'up') {
            const prev = card.previousElementSibling;
            if (prev && prev.classList.contains('sensor-card')) {
                list.insertBefore(card, prev);
            }
        } else if (direction === 'down') {
            let target = card.nextElementSibling;
            while (target && !target.classList.contains('sensor-card')) {
                target = target.nextElementSibling;
            }
            if (target) {
                list.insertBefore(target, card);
            }
        }

        updateOrder();

        card.scrollIntoView({
            block: 'nearest',
            behavior: 'smooth'
        });
    }

    function buildAllowedAngles(deviceCount) {
        if (!Number.isFinite(deviceCount) || deviceCount <= 0) {
            return [0];
        }

        const seg = 360 / deviceCount;
        const cardinals = [0, 90, 180, 270];
        const set = new Set();

        for (const c of cardinals) {
            for (let i = 0; i < deviceCount; i++) {
                const angle = normalizeAngleDeg(c - (seg * i));
                set.add(angle.toFixed(1));
            }
        }

        return Array.from(set)
            .map(v => Number(v))
            .sort((a, b) => a - b);
    }

    function renderAngleOptions(selectedAngle = null) {
        const cards = getSensorCards();
        const count = cards.length;
        const allowedAngles = buildAllowedAngles(count);
        const currentValue = selectedAngle === null
            ? normalizeAngleDeg(ringStartAngle.value || 0)
            : normalizeAngleDeg(selectedAngle);

        ringStartAngle.innerHTML = '';

        allowedAngles.forEach((angle) => {
            const opt = document.createElement('option');
            opt.value = String(angle);
            opt.textContent = formatAngle(angle);
            ringStartAngle.appendChild(opt);
        });

        const exists = allowedAngles.some(v => normalizeAngleDeg(v) === currentValue);

        if (exists) {
            ringStartAngle.value = String(currentValue);
        } else if (allowedAngles.length > 0) {
            ringStartAngle.value = String(allowedAngles[0]);
        }

        const seg = count > 0 ? (360 / count) : 0;
        const segText = Number.isInteger(seg) ? `${seg}` : seg.toFixed(1);

        angleHelp.innerHTML =
            `現在のデバイス数: ${count} 台<br>` +
            `1セクションあたり: ${segText}°<br>` +
            `候補数: ${allowedAngles.length} 個`;
    }

    async function fetchJson(url, options = {}) {
        const res = await fetch(url, options);

        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }

        const json = await res.json();

        if (!json || json.ok !== true) {
            throw new Error(json?.message || 'API呼び出しに失敗しました');
        }

        return json;
    }

    async function getSetting(type, deviceId = null) {
        const params = new URLSearchParams();
        params.set('type', type);

        if (deviceId !== null) {
            params.set('device_id', String(deviceId));
        }

        return fetchJson(`${GET_SETTING_ENDPOINT}?${params.toString()}`, {
            cache: 'no-store'
        });
    }

    async function updateSetting(payload) {
        return fetchJson(UPDATE_SETTING_ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });
    }

    async function getSensorsList(includeThreshold = true) {
        const params = new URLSearchParams();
        params.set('include_threshold', includeThreshold ? '1' : '0');

        return fetchJson(`${GET_SENSORS_LIST_ENDPOINT}?${params.toString()}`, {
            cache: 'no-store'
        });
    }

    async function getCurrentValues() {
        return fetchJson(GET_SENSOR_VALUE_ENDPOINT, {
            cache: 'no-store'
        });
    }

    async function deleteSensorDevice(deviceId) {
        return fetchJson(DELETE_SENSOR_DEVICE_ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                device_id: deviceId
            })
        });
    }

    async function unignoreDetectedDevice(deviceBtAddr) {
        return fetchJson(UNIGNORE_DETECTED_SENSOR_DEVICE_ENDPOINT, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                device_bt_addr: deviceBtAddr
            })
        });
    }

    function ensureIgnoredEmptyNote() {
        const ignoredList = document.getElementById('ignoredList');
        const empty = document.getElementById('ignoredListEmpty');

        if (ignoredList && ignoredList.children.length > 0) {
            if (empty) {
                empty.remove();
            }
            return;
        }

        if (empty) {
            return;
        }

        const note = document.createElement('div');
        note.id = 'ignoredListEmpty';
        note.className = 'empty-note';
        note.textContent = '無視している機器はありません。';

        if (ignoredList) {
            ignoredList.insertAdjacentElement('afterend', note);
        }
    }

    async function loadRingAngle() {
        try {
            const result = await getSetting('ring_start_angle_deg');

            if (result.found) {
                renderAngleOptions(result.setting_value);
            } else {
                renderAngleOptions(0);
            }
        } catch (e) {
            renderAngleOptions(0);
        }
    }

    async function loadCurrentValues() {
        try {
            const json = await getCurrentValues();

            if (!Array.isArray(json.data)) {
                throw new Error('現在値の取得に失敗しました');
            }

            const valueMap = new Map();

            json.data.forEach((row) => {
                valueMap.set(Number(row.device_id), row.measured_value);
            });

            document.querySelectorAll('.current-value-badge').forEach((el) => {
                const deviceId = Number(el.dataset.currentValue);
                const value = Number(valueMap.get(deviceId));

                if (Number.isFinite(value)) {
                    el.textContent = `現在 ${value.toFixed(1)}`;
                } else {
                    el.textContent = '現在 -';
                }
            });
        } catch (e) {
            document.querySelectorAll('.current-value-badge').forEach((el) => {
                el.textContent = '取得失敗';
            });
        }
    }

    async function loadThresholds() {
        try {
            const json = await getSensorsList(true);

            if (!Array.isArray(json.data)) {
                throw new Error('センサー一覧の取得に失敗しました');
            }

            const thresholdMap = new Map();

            json.data.forEach((row) => {
                thresholdMap.set(Number(row.device_id), row.noisy_threshold);
            });

            document.querySelectorAll('.threshold-input').forEach((input) => {
                const deviceId = Number(input.dataset.deviceId);
                const value = thresholdMap.get(deviceId);

                input.value = (value === null || value === undefined || value === '')
                    ? ''
                    : String(value);
            });
        } catch (e) {
            document.querySelectorAll('.threshold-input').forEach((input) => {
                input.value = '';
            });
        }
    }

    async function saveRingAngle() {
        const selected = normalizeAngleDeg(ringStartAngle.value || 0);

        await updateSetting({
            type: 'ring_start_angle_deg',
            setting_value: selected
        });
    }

    async function saveThresholds() {
        const inputs = Array.from(document.querySelectorAll('.threshold-input'));

        for (const input of inputs) {
            input.style.borderColor = '#cbd5e1';
            input.style.boxShadow = 'none';
        }

        const jobs = [];

        for (const input of inputs) {
            const deviceId = Number(input.dataset.deviceId);
            const raw = String(input.value ?? '').trim();

            if (raw === '') {
                continue;
            }

            const value = Number(raw);

            if (!Number.isFinite(value) || value < 0) {
                input.style.borderColor = '#dc2626';
                input.style.boxShadow = '0 0 0 3px rgba(220, 38, 38, 0.12)';
                throw new Error(`ID ${deviceId} の基準音レベルが不正です`);
            }

            jobs.push(
                updateSetting({
                    type: 'device_noisy_threshold',
                    device_id: deviceId,
                    setting_value: Math.round(value * 10) / 10
                })
            );
        }

        await Promise.all(jobs);
    }

    function applyManualOrderInputs() {
        const cards = getSensorCards();

        cards.forEach((card) => {
            const input = card.querySelector('.order-direct-input');
            const raw = String(input?.value ?? '').trim();

            let order = parseInt(raw, 10);

            if (!Number.isFinite(order) || order <= 0) {
                order = 9999;
            }

            card.dataset.manualOrder = String(order);
        });

        cards.sort((a, b) => {
            const oa = parseInt(a.dataset.manualOrder || '9999', 10);
            const ob = parseInt(b.dataset.manualOrder || '9999', 10);

            if (oa !== ob) return oa - ob;

            const ida = parseInt(a.dataset.deviceId || '9999', 10);
            const idb = parseInt(b.dataset.deviceId || '9999', 10);

            return ida - idb;
        });

        cards.forEach((card) => list.appendChild(card));
        updateOrder();
    }

    list.addEventListener('click', async (e) => {
        const upBtn = e.target.closest('.move-up');
        const downBtn = e.target.closest('.move-down');
        const deleteBtn = e.target.closest('.delete-btn');
        const restoreBtn = e.target.closest('.restore-btn');

        if (!upBtn && !downBtn && !deleteBtn && !restoreBtn) {
            return;
        }

        if (restoreBtn) {
            const ignoredItem = e.target.closest('.ignored-item');

            if (!ignoredItem) {
                return;
            }

            const deviceBtAddr = String(restoreBtn.dataset.restoreBtAddr || '').trim();
            const deviceName = String(restoreBtn.dataset.restoreDeviceName || '').trim() || deviceBtAddr;

            const ok = window.confirm(`「${deviceName}」の無視を解除します。\n次回の機器接続画面で候補に表示されます。`);
            if (!ok) {
                return;
            }

            clearGlobalError();
            restoreBtn.disabled = true;
            saveBtn.disabled = true;

            try {
                await unignoreDetectedDevice(deviceBtAddr);
                ignoredItem.remove();
                ensureIgnoredEmptyNote();
            } catch (err) {
                console.error(err);
                showGlobalError(err instanceof Error ? err.message : '無視解除に失敗しました');
                restoreBtn.disabled = false;
            } finally {
                saveBtn.disabled = false;
            }

            return;
        }

        const card = e.target.closest('.sensor-card');

        if (!card) {
            return;
        }

        if (deleteBtn) {
            const deviceId = Number(deleteBtn.dataset.deleteDeviceId || card.dataset.deviceId || 0);
            const deviceName = String(deleteBtn.dataset.deleteDeviceName || '').trim() || `ID ${deviceId}`;

            const ok = window.confirm(`「${deviceName}」を削除します。\nこの操作は元に戻せません。`);
            if (!ok) {
                return;
            }

            clearGlobalError();
            deleteBtn.disabled = true;
            saveBtn.disabled = true;

            try {
                await deleteSensorDevice(deviceId);
                card.remove();
                updateOrder();
                await loadCurrentValues();
            } catch (err) {
                console.error(err);
                showGlobalError(err instanceof Error ? err.message : '機器削除に失敗しました');
                deleteBtn.disabled = false;
            } finally {
                saveBtn.disabled = false;
            }

            return;
        }

        if (upBtn) {
            moveCard(card, 'up');
        } else if (downBtn) {
            moveCard(card, 'down');
        }
    });

    list.addEventListener('change', (e) => {
        const orderInput = e.target.closest('.order-direct-input');
        if (!orderInput) return;
        applyManualOrderInputs();
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        clearGlobalError();

        saveBtn.disabled = true;

        try {
            applyManualOrderInputs();
            await saveRingAngle();
            await saveThresholds();
            form.submit();
        } catch (err) {
            console.error(err);
            showGlobalError(err instanceof Error ? err.message : '保存に失敗しました');
            saveBtn.disabled = false;
        }
    });

    if (toast) {
        window.setTimeout(() => {
            toast.remove();
        }, 2600);
    }

    updateOrder();
    ensureIgnoredEmptyNote();

    Promise.all([
        loadRingAngle(),
        loadThresholds(),
        loadCurrentValues()
    ]).catch((e) => {
        console.error(e);
    });
})();