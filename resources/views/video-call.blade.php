<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Video Call</title>

  <style>
    body { background: #f5f7fb; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 0; }
    .header { text-align:center; padding: 24px 16px; color:#1f2937; }
    .grid { max-width:1080px; margin:0 auto 32px; display:grid; grid-template-columns:640px 1fr; gap:20px; }
    .stage { position:relative; width:640px; height:480px; border-radius:12px; overflow:hidden; background:#000; box-shadow: 0 10px 30px rgba(0,0,0,.15); }
    #remoteVideo, #localVideo, #mixedCanvas { position:absolute; inset:0; width:640px; height:480px; object-fit:cover; }
    #remoteVideo { z-index:1; background:#111; }
    #mixedCanvas { z-index:2; pointer-events:none; }
    #localVideo { z-index:3; width:160px; height:120px; left:420px; top:300px; border:2px solid rgba(255,255,255,.7); border-radius:10px; cursor:grab; }
    .panel { background:#fff; border-radius:12px; padding:20px; box-shadow: 0 10px 30px rgba(0,0,0,.08); color:#334155; }
    .status { display:inline-flex; gap:8px; align-items:center; padding:6px 10px; border-radius:999px; background:#eef2ff; color:#3730a3; font-weight:600; }
    .status.recording { background:#fee2e2; color:#b91c1c; }
    .controls { display:flex; gap:12px; margin-top:12px; }
    .btn { border:0; border-radius:999px; padding:10px 16px; font-weight:700; cursor:pointer; }
    .btn-hangup { background:#ef4444; color:#fff; }
    .btn-hangup:hover { background:#dc2626; }
    .note { font-size:12px; color:#64748b; margin-top:10px; }
    code { background:#f1f5f9; padding:2px 6px; border-radius:6px; }
  </style>
</head>
<body>
  <h2 class="header">Video Call</h2>

  <div class="grid">
    <div class="stage">
      <video id="remoteVideo" playsinline></video>
      <canvas id="mixedCanvas"></canvas>
      <video id="localVideo" playsinline muted></video>
    </div>

    <div class="panel">
      <div id="statusBadge" class="status">Initializing…</div>

      <div class="controls">
        <button id="hangupBtn" class="btn btn-hangup">Hang up</button>
      </div>

      <p class="note">
        Only the <strong>agent</strong> records & uploads (JWT required). Customers can join without a token.
      </p>

      <div class="note">
        <div><strong>callToken</strong>: <code>{{ $meeting->meeting_token ?? ($upload_id ?? '—') }}</code></div>
        <div><strong>API</strong>: <code>{{ url('/api') }}</code></div>
      </div>
    </div>
  </div>

  @php
    // Prefer controller-provided token or the session copy
    $computedJwt = $jwtToken ?? session('jwt_token') ?? null;
  @endphp

  <script>
    window.Laravel = {
      callToken: @json($meeting->meeting_token ?? ($upload_id ?? null)),
      apiUrl: @json(url('/api')),
      jwtToken: @json($computedJwt),
    };

    // Fallback to browser storage if Blade didn't provide it
    (function ensureJwt(){
      try {
        if (!window.Laravel.jwtToken || window.Laravel.jwtToken === "null" || window.Laravel.jwtToken === "") {
          const t = localStorage.getItem('dao_jwt') || sessionStorage.getItem('dao_jwt');
          if (t) window.Laravel.jwtToken = t;
        }
      } catch(e){ console.warn('JWT storage read failed', e); }
    })();

    // Optional helper
    window.getAuthHeaders = function () {
      return window.Laravel.jwtToken ? { Authorization: 'Bearer ' + window.Laravel.jwtToken } : {};
    };
  </script>

  <script src="https://cdn.socket.io/4.7.5/socket.io.min.js"></script>
  <script src="{{ asset('js/video-call.js') }}"></script>
</body>
</html>
