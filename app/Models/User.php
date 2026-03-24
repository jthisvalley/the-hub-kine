<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Traits\HasRewards;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUuids, SoftDeletes, HasRewards;


    protected $fillable = [
        'email',
        'password',
        'role' => UserRole::class,
        'first_name',
        'last_name',
        'phone',
        'date_of_birth',
        'address',
        'city',
        'postal_code',
        'country',
        'avatar_url',
        'is_active',
        'email_verified_at',
        'last_login',
        'remember_token',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login' => 'datetime',
        'date_of_birth' => 'date',
        'is_active' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function getNameAttribute(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function devices()
    {
        return $this->hasMany(UserDevice::class);
    }


    public function kineProfile()
    {
        return $this->hasOne(KineProfile::class);
    }

    public function patientProfile()
    {
        return $this->hasOne(PatientProfile::class);
    }

    public function notificationPreference()
    {
        return $this->hasOne(NotificationPreference::class);
    }

    public function assignedPatients()
    {
        return $this->hasManyThrough(
            User::class,
            KinePatientAssignment::class,
            'kine_id',
            'id',
            'id',
            'patient_id'
        )->where('role', 'patient');
    }

    public function assignedKine()
    {
        return $this->hasOneThrough(
            User::class,
            KinePatientAssignment::class,
            'patient_id',
            'id',
            'id',
            'kine_id'
        )->where('role', 'kine');
    }

    public function programs()
    {
        return $this->hasMany(Program::class, 'kine_id');
    }

    public function assignedPrograms()
    {
        return $this->belongsToMany(Program::class, 'patient_program_assignments', 'patient_id', 'program_id')
            ->withPivot('status', 'started_at', 'completed_at')
            ->withTimestamps();
    }

    public function checkIns()
    {
        return $this->hasMany(CheckIn::class, 'patient_id');
    }

    public function appointmentTypeSettings()
    {
        return $this->hasMany(AppointmentTypeSetting::class, 'kine_id');
    }

    public function availabilitySettings()
    {
        return $this->hasOne(AvailabilitySetting::class, 'kine_id');
    }

    public function appointmentSlots()
    {
        return $this->hasMany(AppointmentSlot::class, 'kine_id');
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'patient_id');
    }

    public function settings()
    {
        return $this->hasOne(UserSettings::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function sentMessages()
    {
        return $this->hasMany(Message::class, 'sender_id');
    }

    public function receivedMessages()
    {
        return $this->hasMany(Message::class, 'receiver_id');
    }

    public function achievements()
    {
        return $this->belongsToMany(
            Achievement::class,
            'patient_achievements',
            'patient_id',
            'achievement_id'
        )
        ->withPivot('unlocked', 'unlocked_at', 'progress')
        ->withTimestamps();
    }


    public function patientDocuments()
    {
        return $this->hasMany(PatientDocument::class, 'patient_id');
    }

    public function cartItems()
    {
        return $this->hasMany(CartItem::class, 'patient_id');
    }

    public function dailyCheckins()
    {
        return $this->hasMany(DailyCheckin::class, 'patient_id');
    }

    public function exerciseSessions()
    {
        return $this->hasMany(ExerciseSession::class, 'patient_id');
    }

    public function goals()
    {
        return $this->hasMany(PatientGoal::class, 'patient_id');
    }

    public function assignedGoals()
    {
        return $this->hasMany(PatientGoal::class, 'kine_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'patient_id');
    }

    public function loyaltyPoints()
    {
        return $this->hasOne(LoyaltyPoints::class, 'patient_id');
    }

    public function pointsTransactions()
    {
        return $this->hasMany(PointsTransaction::class, 'patient_id');
    }

    public function milestones()
    {
        return $this->hasMany(Milestone::class, 'patient_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'patient_id');
    }

    public function assignedKines()
    {
        return $this->belongsToMany(User::class, 'kine_patient_assignments', 'patient_id', 'kine_id');
    }

    public function progressMetrics()
    {
        return $this->hasMany(ProgressMetric::class, 'patient_id');
    }

    public function progressReports()
    {
        return $this->hasMany(ProgressReport::class, 'patient_id');
    }

    public function authoredProgressReports()
    {
        return $this->hasMany(ProgressReport::class, 'kine_id');
    }

    public function redeemedRewards()
    {
        return $this->hasMany(RedeemedReward::class, 'patient_id');
    }

    public function slots()
    {
        return $this->hasMany(AppointmentSlot::class, 'kine_id');
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class, 'patient_id');
    }

    public function programAssignments()
    {
        return $this->hasMany(PatientProgramAssignment::class, 'patient_id');
    }


    public function productRecommendations()
    {
        return $this->hasMany(ProductRecommendation::class, 'patient_id');
    }

    public function assignedProducts()
    {
        return $this->hasManyThrough(
            Product::class,
            ProductRecommendation::class,
            'patient_id',
            'id',
            'id',
            'product_id'
        );
    }

    public function kineProducts()
    {
        return $this->hasMany(Product::class, 'kine_id');
    }


    public function appointmentsWithSlot()
    {
        return $this->hasMany(Appointment::class, 'patient_id')
            ->where('status', 'completed')
            ->with('slot');
    }

    public function upcomingAppointments()
    {
        return $this->hasMany(Appointment::class, 'patient_id')
            ->where('status', 'scheduled')
            ->whereHas('slot', function ($query) {
                $query->where('start_time', '>', now());
            })
            ->with('slot')
            ->orderBy(function ($query) {
                $query->select('start_time')
                    ->from('appointment_slots')
                    ->whereColumn('appointment_slots.id', 'appointments.slot_id')
                    ->limit(1);
            }, 'asc');
    }

    public function assignedQuotes()
    {
        return $this->belongsToMany(
            Quote::class,
            'kine_quotes',
            'patient_id',
            'quote_id'
        )->withPivot(['kine_id', 'is_active', 'order_index'])
        ->withTimestamps();
    }

    public function painReports()
    {
        return $this->hasMany(PainReport::class, 'patient_id');
    }

    public function assignedPainReports()
    {
        return $this->hasMany(PainReport::class, 'kine_id');
    }

        public function reviewedPainReports()
    {
        return $this->hasMany(PainReport::class, 'reviewed_by');
    }


    public function scopeKines($query)
    {
        return $query->where('role', 'kine');
    }

    public function scopePatients($query)
    {
        return $query->where('role', 'patient');
    }

    public function scopeWithPatientData($query)
    {
        return $query->with(['patientProfile', 'goals', 'loyaltyPoints', 'subscription']);
    }

    public function scopeWithKineData($query)
    {
        return $query->with(['kineProfile', 'slots', 'programs', 'assignedPatients']);
    }

    public function scopeWithProfile($query)
    {
        return $query->with(['patientProfile', 'kineProfile']);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function isKine()
    {
        return $this->role === 'kine';
    }

    public function isPatient()
    {
        return $this->role === 'patient';
    }

    public function isAdmin()
    {
        return $this->role === 'admin';
    }


    public function fullName()
    {
        return $this->first_name . ' ' . $this->last_name;
    }
}
