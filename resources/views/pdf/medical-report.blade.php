<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Compte-rendu médical - {{ $patient->first_name }} {{ $patient->last_name }}</title>
    <style>
        body {
            font-family: 'DejaVu Sans', sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 20px;
        }

        .header {
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }

        .header h1 {
            color: #2c3e50;
            margin: 0 0 10px 0;
            font-size: 24px;
        }

        .patient-info {
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 30px;
        }

        .patient-info h2 {
            color: #2c3e50;
            margin-top: 0;
            font-size: 18px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 10px;
        }

        .info-item {
            margin-bottom: 5px;
        }

        .info-label {
            font-weight: bold;
            color: #555;
        }

        .medical-content {
            margin-top: 30px;
        }

        .medical-content h2 {
            color: #2c3e50;
            border-bottom: 1px solid #ddd;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }

        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 12px;
            color: #666;
            text-align: center;
        }

        .signature {
            margin-top: 40px;
            text-align: right;
        }

        .signature-line {
            border-top: 1px solid #333;
            width: 200px;
            margin-left: auto;
            margin-top: 40px;
        }

        /* Rich text content styles */
        .content {
            line-height: 1.8;
        }

        .content h1,
        .content h2,
        .content h3 {
            color: #2c3e50;
            margin-top: 20px;
            margin-bottom: 10px;
        }

        .content p {
            margin-bottom: 15px;
        }

        .content ul,
        .content ol {
            margin-left: 20px;
            margin-bottom: 15px;
        }

        .content li {
            margin-bottom: 5px;
        }

        .content img {
            max-width: 100%;
            height: auto;
            margin: 15px 0;
        }

        .content blockquote {
            border-left: 4px solid #3498db;
            padding-left: 15px;
            margin: 15px 0;
            font-style: italic;
            color: #555;
        }

        .content table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }

        .content th,
        .content td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }

        .content th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>Compte-rendu médical</h1>
        <p>Généré le {{ $date }} à {{ $time }}</p>
    </div>

    <div class="patient-info">
        <h2>Informations du patient</h2>
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">Nom complet:</span>
                {{ $patient->first_name }} {{ $patient->last_name }}
            </div>
            <div class="info-item">
                <span class="info-label">Date de naissance:</span>
                {{ $patient->date_of_birth ? \Carbon\Carbon::parse($patient->date_of_birth)->format('d/m/Y') : 'Non spécifiée' }}
            </div>
            @if ($patient->patientProfile)
                <div class="info-item">
                    <span class="info-label">Genre:</span>
                    {{ $patient->patientProfile->gender == 'male' ? 'Homme' : ($patient->patientProfile->gender == 'female' ? 'Femme' : 'Autre') }}
                </div>
            @endif
            <div class="info-item">
                <span class="info-label">ID Patient:</span>
                {{ $patient->id }}
            </div>
        </div>
    </div>

    <div class="medical-content">
        <h2>Notes médicales</h2>
        <div class="content">
            {!! $content !!}
        </div>
    </div>

    <div class="signature">
        <p>Rédigé par:</p>
        <p><strong>{{ $kine->first_name }} {{ $kine->last_name }}</strong></p>
        <p>Kinésithérapeute</p>
        <div class="signature-line"></div>
        <p>Signature</p>
    </div>

    <div class="footer">
        <p>Document généré automatiquement par le système de gestion médicale</p>
        <p>Confidential - Usage médical uniquement</p>
    </div>
</body>

</html>
