<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
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
            background: #f9f9f9;
            padding: 30px;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 10px 10px;
        }

        .button {
            display: inline-block;
            padding: 12px 24px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
            font-weight: 500;
        }

        .button:hover {
            background: #5a67d8;
        }

        .footer {
            text-align: center;
            margin-top: 20px;
            color: #999;
            font-size: 12px;
        }

        .details {
            background: white;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            border-left: 4px solid #667eea;
        }

        @media (prefers-color-scheme: dark) {
            body {
                background: #1a1a1a;
                color: #f0f0f0;
            }

            .content {
                background: #2d2d2d;
                border-color: #404040;
            }

            .details {
                background: #333;
            }

            .footer {
                color: #666;
            }
        }
    </style>
</head>

<body>
    <div class="header">
        <h1 style="margin:0;">LeHubKiné</h1>
    </div>

    <div class="content">
        <h2>{{ $title }}</h2>

        <p>Bonjour {{ $user->first_name }},</p>

        <p>{{ $message }}</p>

        @if (isset($data['appointment_id']))
            <div class="details">
                <h4 style="margin-top:0;">Détails du rendez-vous:</h4>
                @if (isset($data['start_time']))
                    <p>📅 <strong>Date:</strong> {{ \Carbon\Carbon::parse($data['start_time'])->format('d/m/Y H:i') }}
                    </p>
                @endif
                @if (isset($data['kine_name']))
                    <p>👨‍⚕️ <strong>Kiné:</strong> {{ $data['kine_name'] }}</p>
                @endif
                @if (isset($data['location']))
                    <p>📍 <strong>Lieu:</strong> {{ $data['location'] === 'online' ? 'En ligne' : 'Au cabinet' }}</p>
                @endif
                @if (isset($data['is_online']) && $data['is_online'] && isset($data['video_link']))
                    <p>🔗 <strong>Lien visio:</strong> <a href="{{ $data['video_link'] }}">{{ $data['video_link'] }}</a>
                    </p>
                @endif
            </div>
        @endif

        @if (isset($actionUrl))
            <a href="{{ $actionUrl }}" class="button">Voir les détails</a>
        @endif

        <p style="margin-top: 30px;">
            Cordialement,<br>
            L'équipe LeHubKiné
        </p>
    </div>

    <div class="footer">
        <p>Cet email a été envoyé automatiquement, merci de ne pas y répondre.</p>
        <p>Vous pouvez modifier vos préférences de notification dans les paramètres de votre compte.</p>
        <p>&copy; {{ date('Y') }} LeHubKiné. Tous droits réservés.</p>
    </div>
</body>

</html>
