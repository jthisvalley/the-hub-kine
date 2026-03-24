<!DOCTYPE html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rapport de progression</title>
    <style>
        body {
            font-family: 'Helvetica', 'Arial', sans-serif;
            line-height: 1.6;
            color: #333;
            margin: 0;
            padding: 20px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #3b82f6;
        }

        .clinic-info {
            text-align: right;
            color: #666;
            font-size: 12px;
        }

        .clinic-info h2 {
            color: #3b82f6;
            margin: 0 0 5px 0;
        }

        .title {
            text-align: center;
            margin: 30px 0;
        }

        .title h1 {
            color: #3b82f6;
            font-size: 24px;
            margin: 0;
        }

        .title p {
            color: #666;
            margin: 5px 0 0 0;
        }

        .patient-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 30px;
        }

        .patient-info table {
            width: 100%;
            border-collapse: collapse;
        }

        .patient-info td {
            padding: 5px 10px;
            font-size: 14px;
        }

        .patient-info td:first-child {
            font-weight: bold;
            width: 120px;
        }

        .section {
            margin: 30px 0;
        }

        .section h2 {
            color: #3b82f6;
            font-size: 18px;
            margin: 0 0 15px 0;
            padding-bottom: 5px;
            border-bottom: 1px solid #ddd;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin: 20px 0;
        }

        .metric-card {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }

        .metric-value {
            font-size: 24px;
            font-weight: bold;
            color: #3b82f6;
        }

        .metric-label {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }

        .metric-improvement {
            font-size: 12px;
            margin-top: 5px;
        }

        .improvement-positive {
            color: #10b981;
        }

        .improvement-negative {
            color: #ef4444;
        }

        .stats-table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }

        .stats-table td {
            padding: 8px 12px;
            border: 1px solid #ddd;
        }

        .stats-table td:first-child {
            font-weight: bold;
            background: #f8f9fa;
        }

        .observations {
            background: #eff6ff;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #3b82f6;
        }

        .recommendations {
            background: #f0fdf4;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #22c55e;
        }

        .next-steps {
            background: #faf5ff;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #a855f7;
        }

        .footer {
            margin-top: 50px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            text-align: center;
            font-size: 12px;
            color: #999;
        }
    </style>
</head>

<body>
    <div class="header">
        {{-- <div>
            <img src="{{ $clinic_info['logo'] }}" alt="Logo" style="height: 50px;">
        </div> --}}
        <div class="clinic-info">
            <h2>{{ $clinic_info['name'] }}</h2>
            <p>{{ $clinic_info['address'] }}</p>
            <p>Tél: {{ $clinic_info['phone'] }} | Email: {{ $clinic_info['email'] }}</p>
        </div>
    </div>

    <div class="title">
        <h1>Rapport de progression</h1>
        <p>Généré le {{ $generated_at }}</p>
    </div>

    <div class="patient-info">
        <table>
            <tr>
                <td>Patient:</td>
                <td>{{ $patient->first_name }} {{ $patient->last_name }}</td>
                <td>Date de naissance:</td>
                <td>{{ $patient->date_of_birth ? \Carbon\Carbon::parse($patient->date_of_birth)->format('d/m/Y') : 'Non renseignée' }}
                </td>
            </tr>
            <tr>
                <td>Kinésithérapeute:</td>
                <td>{{ $kine->first_name }} {{ $kine->last_name }}</td>
                <td>Période du rapport:</td>
                <td>Du {{ $report->report_date->subDays(30)->format('d/m/Y') }} au
                    {{ $report->report_date->format('d/m/Y') }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Résumé</h2>
        <p>{{ $report->summary }}</p>
    </div>

    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-value">{{ $report->adherence_rate }}%</div>
            <div class="metric-label">Adhérence</div>
        </div>
        <div class="metric-card">
            <div class="metric-value">{{ $report->pain_level_current }}/10</div>
            <div class="metric-label">Douleur actuelle</div>
            <div
                class="metric-improvement {{ $report->pain_improvement > 0 ? 'improvement-positive' : ($report->pain_improvement < 0 ? 'improvement-negative' : '') }}">
                @if ($report->pain_improvement != 0)
                    {{ $report->pain_improvement > 0 ? '-' : '+' }}{{ abs($report->pain_improvement) }} points
                @endif
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-value">{{ $report->mobility_score_current }}%</div>
            <div class="metric-label">Mobilité</div>
            <div
                class="metric-improvement {{ $report->mobility_improvement > 0 ? 'improvement-positive' : 'improvement-negative' }}">
                @if ($report->mobility_improvement != 0)
                    {{ $report->mobility_improvement > 0 ? '+' : '' }}{{ $report->mobility_improvement }}%
                @endif
            </div>
        </div>
        <div class="metric-card">
            <div class="metric-value">{{ $report->completed_sessions }}</div>
            <div class="metric-label">Séances complétées</div>
            <div class="metric-improvement">
                sur {{ $report->total_sessions }} séances
            </div>
        </div>
    </div>

    <div class="section">
        <h2>Détails des métriques</h2>
        <table class="stats-table">
            <tr>
                <td>Douleur - Début de période</td>
                <td>{{ $report->pain_level_start }}/10</td>
            </tr>
            <tr>
                <td>Douleur - Fin de période</td>
                <td>{{ $report->pain_level_current }}/10</td>
            </tr>
            <tr>
                <td>Amélioration de la douleur</td>
                <td
                    class="{{ $report->pain_improvement > 0 ? 'improvement-positive' : ($report->pain_improvement < 0 ? 'improvement-negative' : '') }}">
                    {{ $report->pain_improvement > 0 ? '-' : '' }}{{ abs($report->pain_improvement) }} points
                </td>
            </tr>
            <tr>
                <td>Mobilité - Début de période</td>
                <td>{{ $report->mobility_score_start }}%</td>
            </tr>
            <tr>
                <td>Mobilité - Fin de période</td>
                <td>{{ $report->mobility_score_current }}%</td>
            </tr>
            <tr>
                <td>Amélioration de la mobilité</td>
                <td class="{{ $report->mobility_improvement > 0 ? 'improvement-positive' : 'improvement-negative' }}">
                    {{ $report->mobility_improvement > 0 ? '+' : '' }}{{ $report->mobility_improvement }}%
                </td>
            </tr>
            <tr>
                <td>Force - Amélioration</td>
                <td
                    class="{{ $report->strength_improvement > 0 ? 'improvement-positive' : ($report->strength_improvement < 0 ? 'improvement-negative' : '') }}">
                    {{ $report->strength_improvement > 0 ? '+' : '' }}{{ $report->strength_improvement }}%
                </td>
            </tr>
            <tr>
                <td>Flexibilité - Amélioration</td>
                <td
                    class="{{ $report->flexibility_improvement > 0 ? 'improvement-positive' : ($report->flexibility_improvement < 0 ? 'improvement-negative' : '') }}">
                    {{ $report->flexibility_improvement > 0 ? '+' : '' }}{{ $report->flexibility_improvement }}%
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Statistiques des séances</h2>
        <table class="stats-table">
            <tr>
                <td>Total des séances</td>
                <td>{{ $report->total_sessions }}</td>
            </tr>
            <tr>
                <td>Séances complétées</td>
                <td>{{ $report->completed_sessions }}</td>
            </tr>
            <tr>
                <td>Séances manquées</td>
                <td>{{ $report->missed_sessions }}</td>
            </tr>
            <tr>
                <td>Taux de complétion</td>
                <td>{{ number_format($report->completion_rate, 1) }}%</td>
            </tr>
            <tr>
                <td>Durée moyenne des séances</td>
                <td>{{ $report->average_session_duration }} minutes</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Objectifs</h2>
        <table class="stats-table">
            <tr>
                <td>Objectifs atteints</td>
                <td class="improvement-positive">{{ $report->goals_achieved }}</td>
            </tr>
            <tr>
                <td>Objectifs en cours</td>
                <td>{{ $report->goals_in_progress }}</td>
            </tr>
            <tr>
                <td>Objectifs non atteints</td>
                <td class="improvement-negative">{{ $report->goals_failed }}</td>
            </tr>
        </table>
    </div>

    @if ($report->kine_observations)
        <div class="section">
            <h2>Observations du kinésithérapeute</h2>
            <div class="observations">
                {!! nl2br(e($report->kine_observations)) !!}
            </div>
        </div>
    @endif

    @if ($report->kine_recommendations)
        <div class="section">
            <h2>Recommandations</h2>
            <div class="recommendations">
                {!! nl2br(e($report->kine_recommendations)) !!}
            </div>
        </div>
    @endif

    @if ($report->next_steps)
        <div class="section">
            <h2>Prochaines étapes</h2>
            <div class="next-steps">
                {!! nl2br(e($report->next_steps)) !!}
            </div>
        </div>
    @endif

    @if ($report->patient_comments)
        <div class="section">
            <h2>Commentaires du patient</h2>
            <div class="patient-comments">
                <p>{{ $report->patient_comments }}</p>
            </div>
        </div>
    @endif

    @if ($report->patient_satisfaction)
        <div class="section">
            <h2>Satisfaction du patient</h2>
            <div class="satisfaction">
                @for ($i = 1; $i <= 5; $i++)
                    @if ($i <= $report->patient_satisfaction)
                        ⭐
                    @else
                        ☆
                    @endif
                @endfor
                ({{ $report->patient_satisfaction }}/5)
            </div>
        </div>
    @endif

    <div class="footer">
        <p>Document généré automatiquement par Le Hub Kiné - Tous droits réservés</p>
        <p>Ce rapport est confidentiel et destiné uniquement à {{ $patient->first_name }} {{ $patient->last_name }} et
            son équipe médicale.</p>
    </div>
</body>

</html>
