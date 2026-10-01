<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>M-Pesa STK Push Integration</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #4CAF50;
            --primary-dark: #388E3C;
            --bg: #0f172a;
            --card-bg: #1e293b;
            --text: #f8fafc;
            --text-muted: #94a3b8;
            --glass: rgba(255, 255, 255, 0.05);
            --glass-border: rgba(255, 255, 255, 0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Outfit', sans-serif;
        }

        body {
            background-color: var(--bg);
            color: var(--text);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 450px;
            background: var(--card-bg);
            padding: 40px;
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            border: 1px solid var(--glass-border);
            backdrop-filter: blur(10px);
            position: relative;
            overflow: hidden;
        }

        .container::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(76, 175, 80, 0.1) 0%, transparent 70%);
            z-index: 0;
            pointer-events: none;
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
            position: relative;
            z-index: 1;
        }

        .header h1 {
            font-size: 28px;
            font-weight: 600;
            margin-bottom: 8px;
            background: linear-gradient(to right, #4CAF50, #8BC34A);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .header p {
            color: var(--text-muted);
            font-size: 14px;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            color: var(--text-muted);
            font-weight: 400;
        }

        input {
            width: 100%;
            padding: 14px 16px;
            background: var(--glass);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            color: var(--text);
            font-size: 16px;
            transition: all 0.3s ease;
            outline: none;
        }

        input:focus {
            border-color: var(--primary);
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 0 4px rgba(76, 175, 80, 0.1);
        }

        .btn {
            width: 100%;
            padding: 16px;
            background: var(--primary);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
            position: relative;
            z-index: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
        }

        .btn:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(76, 175, 80, 0.3);
        }

        .btn:active {
            transform: translateY(0);
        }

        .btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        #response {
            margin-top: 25px;
            padding: 15px;
            border-radius: 12px;
            font-size: 14px;
            display: none;
            position: relative;
            z-index: 1;
            text-align: center;
        }

        .success {
            background: rgba(76, 175, 80, 0.1);
            color: #81c784;
            border: 1px solid rgba(76, 175, 80, 0.2);
        }

        .error {
            background: rgba(239, 68, 68, 0.1);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .loader {
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 0.8s linear infinite;
            display: none;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .footer {
            text-align: center;
            margin-top: 30px;
            font-size: 12px;
            color: var(--text-muted);
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="header">
            <h1>Lipa na M-Pesa</h1>
            <p>Enter details to initiate payment prompt</p>
        </div>

        <form id="paymentForm">
            <div class="form-group">
                <label for="phone">Phone Number</label>
                <input type="text" id="phone" name="phone" placeholder="e.g. 254712345678" required>
            </div>
            <div class="form-group">
                <label for="amount">Amount (KES)</label>
                <input type="number" id="amount" name="amount" placeholder="e.g. 1" required min="1">
            </div>
            <button type="submit" class="btn" id="submitBtn">
                <span class="loader" id="loader"></span>
                <span id="btnText">Pay Now</span>
            </button>
        </form>

        <div id="response"></div>

        <div class="footer">
            Securely powered by Daraja API
        </div>
    </div>

    <script>
        const form = document.getElementById('paymentForm');
        const submitBtn = document.getElementById('submitBtn');
        const loader = document.getElementById('loader');
        const btnText = document.getElementById('btnText');
        const responseDiv = document.getElementById('response');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            // UI State: Loading
            submitBtn.disabled = true;
            loader.style.display = 'block';
            btnText.innerText = 'Processing...';
            responseDiv.style.display = 'none';

            const formData = new FormData(form);

            try {
                const response = await fetch('stk_push.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                responseDiv.style.display = 'block';
                
                if (data.ResponseCode === "0") {
                    responseDiv.className = 'success';
                    responseDiv.innerText = "Prompt sent successfully! Please check your phone to complete the payment.";
                    form.reset();
                } else if (data.error) {
                    responseDiv.className = 'error';
                    responseDiv.innerText = "Error: " + data.error;
                } else {
                    responseDiv.className = 'error';
                    responseDiv.innerText = "Error: " + (data.errorMessage || "Something went wrong.");
                }
            } catch (error) {
                responseDiv.style.display = 'block';
                responseDiv.className = 'error';
                responseDiv.innerText = "Connection failed. Please try again.";
                console.error(error);
            } finally {
                submitBtn.disabled = false;
                loader.style.display = 'none';
                btnText.innerText = 'Pay Now';
            }
        });
    </script>
</body>
</html>
