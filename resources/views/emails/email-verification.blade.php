<!DOCTYPE html>
<html>

<head>
    <title>Vérification d'Email</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
        }

        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: #3b82f6;
            color: white;
            padding: 20px;
            text-align: center;
        }

        .content {
            background: #f8fafc;
            padding: 30px;
        }

        .otp-code {
            font-size: 32px;
            font-weight: bold;
            text-align: center;
            color: #3b82f6;
            margin: 20px 0;
            letter-spacing: 8px;
        }

        .footer {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
            text-align: center;
            color: #6b7280;
            font-size: 12px;
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>Le Hub Kiné</h1>
            <p>Plateforme Kinésithérapie</p>
        </div>

        <div class="content">
            <h2>Vérification de votre adresse email</h2>

            <p>Bonjour,</p>

            <p>Merci de vous être inscrit sur Le Hub Kiné. Pour activer votre compte, veuillez utiliser le code de
                vérification suivant :</p>

            <div class="otp-code">{{ $otp }}</div>

            <p>Ce code expirera dans <strong>15 minutes</strong>.</p>

            <p>Si vous n'avez pas créé de compte sur notre plateforme, vous pouvez ignorer cet email.</p>

            <p>À bientôt sur Le Hub Kiné !</p>

            <p><strong>L'équipe Le Hub Kiné</strong></p>
        </div>

        <div class="footer">
            <p>Cet email a été envoyé automatiquement. Merci de ne pas y répondre.</p>
            <p>© {{ date('Y') }} Le Hub Kiné. Tous droits réservés.</p>
        </div>
    </div>
</body>

</html>
