<?php

namespace App\Enums;

enum AchievementType: string
{
    case STREAK = 'streak';
    case MILESTONE = 'milestone';
    case EXERCISE = 'exercise';
    case CONSISTENCY = 'consistency';
    case SOCIAL = 'social';
    case CHALLENGE = 'challenge';
}
