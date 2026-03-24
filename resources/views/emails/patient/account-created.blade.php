<!DOCTYPE html>
<html>

<head>
    <title>Votre compte a été créé</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
            border-radius: 10px 10px 0 0;
        }

        .content {
            padding: 30px;
            background: #f9f9f9;
            border-radius: 0 0 10px 10px;
        }

        .button {
            display: inline-block;
            padding: 12px 24px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
        }

        .info-box {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 5px;
            padding: 15px;
            margin: 15px 0;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>Bienvenue sur notre plateforme !</h1>
    </div>
    <div class="content">
        <p>Bonjour <strong>{{ $user->first_name }} {{ $user->last_name }}</strong>,</p>

        <p>Votre compte a été créé avec succès par votre kinésithérapeute <strong>{{ $kine->first_name }}
                {{ $kine->last_name }}</strong>.</p>

        <div class="info-box">
            <h3>Vos identifiants de connexion :</h3>
            <p><strong>Email :</strong> {{ $user->email }}</p>
            <p><strong>Mot de passe temporaire :</strong> {{ $password }}</p>
        </div>

        <p style="color: #e74c3c;">
            <strong>Important :</strong> Pour des raisons de sécurité, nous vous recommandons de changer votre mot de
            passe dès votre première connexion.
        </p>

        <a href="{{ config('app.url') }}/login" class="button">Se connecter à mon compte</a>

        <h3>Votre kinésithérapeute :</h3>
        <p>
            {{ $kine->first_name }} {{ $kine->last_name }}<br>
            {{ $kine->phone ?? '' }}
        </p>

        <p>N'hésitez pas à nous contacter si vous avez des questions.</p>

        <p>Cordialement,<br>
            L'équipe de votre plateforme de kinésithérapie</p>
    </div>
</body>

</html>
