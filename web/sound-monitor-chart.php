<!doctype html>
<html lang="ja">

<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Sensor Dashboard</title>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/luxon@3.4.4/build/global/luxon.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-luxon@1.3.1/dist/chartjs-adapter-luxon.umd.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-zoom@2.0.1/dist/chartjs-plugin-zoom.umd.min.js"></script>

  <link rel="stylesheet" href="./css/sound-monitor-chart.css">
</head>

<body>
  <div class="layout">
    <aside class="sidebar" id="sidebarCard">
      <div class="sidebarHeader">
        <h1 class="title">Sensor Dashboard</h1>
        <p class="subTitle">
          検索条件を左側に集約しています。<br>
          閾値判定と補助線は level のみに適用します。
        </p>
      </div>

      <div class="sidebarBody">
        <div class="field">
          <label>過去時間 (hours)</label>
          <select id="hours">
            <option value="1">1</option>
            <option value="3">3</option>
            <option value="6" selected>6</option>
            <option value="12">12</option>
            <option value="24">24</option>
          </select>
        </div>

        <div class="field">
          <label>device（自動列挙）</label>
          <select id="deviceSelect">
            <option value="">全て</option>
          </select>
        </div>

        <div class="field">
          <label>device_id（手入力・任意）</label>
          <input id="deviceInput" type="number" min="1" step="1" placeholder="空なら未使用" />
        </div>

        <div class="field">
          <label>compact</label>
          <select id="compact">
            <option value="0" selected>0（通常）</option>
            <option value="1">1（軽量）</option>
          </select>
        </div>

        <div class="field">
          <label>自動更新</label>
          <select id="auto">
            <option value="0" selected>OFF</option>
            <option value="1">ON</option>
          </select>
        </div>

        <div class="field">
          <label>更新間隔 (秒)</label>
          <select id="intervalSec">
            <option value="5">5</option>
            <option value="10" selected>10</option>
            <option value="20">20</option>
            <option value="60">60</option>
          </select>
        </div>

        <div class="field">
          <label>描画モード</label>
          <select id="mode">
            <option value="all" selected>全device</option>
            <option value="one">選択deviceのみ</option>
          </select>
        </div>

        <div class="field">
          <label>表示値種別</label>
          <select id="valueTypeMode">
            <option value="all" selected>level + baseline</option>
            <option value="level">levelのみ</option>
            <option value="baseline">baselineのみ</option>
          </select>
        </div>

        <div class="field">
          <label>平滑化（移動平均）</label>
          <select id="smoothSec">
            <option value="0" selected>0秒（なし）</option>
            <option value="3">3秒</option>
            <option value="10">10秒</option>
            <option value="30">30秒</option>
          </select>
        </div>

        <div class="field">
          <label>警告閾値 (warn / levelのみ)</label>
          <input id="warnTh" type="number" step="0.1" value="20" />
        </div>

        <div class="field">
          <label>危険閾値 (crit / levelのみ)</label>
          <input id="critTh" type="number" step="0.1" value="30" />
        </div>

        <div class="field">
          <label>操作</label>
          <button id="reload">読み込み</button>
        </div>
      </div>

      <div class="sidebarFooter">
        <div class="status" id="status">ready</div>
      </div>
    </aside>

    <main class="main">
      <section class="card chartCard" id="chartCard">
        <div class="chartHeading">
          <div class="chartTitleWrap">
            <div class="chartTitle" id="chartTitle">センサー推移</div>
            <div class="chartSub" id="chartSubtitle">--</div>
          </div>
          <span class="pill" id="valueTypePill">level + baseline</span>
        </div>

        <div class="chartArea">
          <canvas id="chart"></canvas>
        </div>

        <div class="chartFooter">
          <span class="pill">Zoom: ホイール / Pinch</span>
          <span class="pill">Pan: ドラッグ</span>
          <button id="resetZoom" style="padding:6px 10px;">ズーム解除</button>
          <span class="pill" id="lastUpdated">--</span>
        </div>
      </section>

      <section class="bottomGrid">
        <div class="card ringCard" id="ringCard">
          <canvas id="ringCanvas" width="300" height="300"></canvas>
        </div>

        <div class="card latestCard" id="latestCard">
          <div class="latestHeader">
            <div style="font-weight:900;">最新値</div>
            <span class="pill" id="latestTime">--</span>
          </div>
          <div class="latestScroll">
            <table class="table" id="latestTable">
              <thead>
                <tr>
                  <th>device</th>
                  <th>bt_addr</th>
                  <th>measured_datetime</th>
                  <th>level</th>
                  <th>baseline</th>
                  <th>state</th>
                </tr>
              </thead>
              <tbody>
                <tr>
                  <td colspan="6" style="color:#6b7280;">データ未取得</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </section>
    </main>
  </div>

  <script src="./js/sound-monitor-chart.js"></script>
</body>

</html>