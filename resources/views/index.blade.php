<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }

        .container {
            text-align: center;
            padding: 2rem;
            max-width: 600px;
        }

        h1 {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
        }

        p {
            font-size: 1.25rem;
            opacity: 0.9;
            line-height: 1.6;
            margin-bottom: 2rem;
        }

        .badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.2);
            padding: 0.5rem 1rem;
            border-radius: 20px;
            font-size: 0.875rem;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.3);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Welcome</h1>
        <p>Thank you for visiting. Our service is running smoothly.</p>
        <span class="badge">Powered by EES {{ app()->version() }}</span>
    </div>
</body>
</html>
