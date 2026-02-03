<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Access Denied</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
            background: #f5f5f5;
        }

        .error-container {
            text-align: center;
            padding: 40px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            max-width: 500px;
        }

        h1 {
            color: #cc0000;
            margin-bottom: 20px;
        }

        p {
            color: #333;
            line-height: 1.6;
        }
    </style>
</head>

<body>
    <div class="error-container">
        <h1>Access Denied</h1>
        <p>
            <?php p($_['message']); ?>
        </p>
    </div>
</body>

</html>