<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>DAO Video Calling Application</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', 'Roboto', Arial, sans-serif;
            margin: 0;
        }
        .container {
            max-width: 480px;
            margin: 80px auto 0 auto;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 8px 32px rgba(60,60,120,0.12);
            padding: 36px 32px 32px 32px;
            text-align: center;
        }
        .dao-logo {
            width: 70px;
            margin-bottom: 18px;
        }
        h1 {
            color: #2d3a4b;
            font-size: 2.1rem;
            font-weight: 700;
            margin-bottom: 10px;
            letter-spacing: 1px;
        }
        .subtitle {
            color: #5a6a85;
            font-size: 1.08rem;
            margin-bottom: 28px;
        }
        .action-btn {
            background: linear-gradient(90deg, #3498db 0%, #6dd5fa 100%);
            color: #fff;
            border: none;
            border-radius: 8px;
            padding: 12px 32px;
            font-weight: 600;
            font-size: 1.08rem;
            cursor: pointer;
            box-shadow: 0 2px 8px rgba(52,152,219,0.10);
            transition: background 0.2s, box-shadow 0.2s, transform 0.1s;
            outline: none;
            margin-top: 18px;
        }
        .action-btn:active {
            background: linear-gradient(90deg, #2980b9 0%, #3498db 100%);
            transform: scale(0.97);
        }
        .footer {
            margin-top: 38px;
            color: #888;
            font-size: 1.04rem;
            letter-spacing: 0.2px;
            text-align: center
        }
    </style>
</head>
<body>
    <div class="container">
        <img src="https://cdn-icons-png.flaticon.com/512/3062/3062634.png" alt="DAO Logo" class="dao-logo">
        <h1>DAO Video Calling</h1>
        <div class="subtitle">
            Secure, fast, and easy video meetings for your business.<br>
            Schedule, join, and record calls with a single link.
        </div>
        <a href="#" class="action-btn">Join a Demo Call</a>
        <div style="margin-top: 18px;">
            <a href="#" style="color:#3498db; text-decoration:underline; font-size:0.98rem;">Try Interface Demo</a>
        </div>
    </div>
    <div class="footer">
        Powered by Payvance DAO &copy; {{ date('Y') }}
    </div>
</body>
</html>