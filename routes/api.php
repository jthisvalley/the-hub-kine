<?php

use App\Http\Controllers\Api\Kine\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Kine\Kine\KineController;
use App\Http\Controllers\Api\Kine\PatientController;
use App\Http\Controllers\Api\Kine\ProgramController;
use App\Http\Controllers\Api\Kine\AppointmentController;
use App\Http\Controllers\Api\Kine\CalendarController;
use App\Http\Controllers\Api\Kine\DashboardController;
use App\Http\Controllers\Api\Kine\ExerciseController;
use App\Http\Controllers\Api\Kine\FileController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\Kine\AppointmentReportController;
use App\Http\Controllers\Api\Kine\AvailabilitySettingsController;
use App\Http\Controllers\Api\Kine\MedicalReportController;
use App\Http\Controllers\Api\Kine\PatientDocumentController;
use App\Http\Controllers\Api\Kine\ExerciseSessionController;
use App\Http\Controllers\Api\Kine\ExerciseCategoryController;
use App\Http\Controllers\Api\Kine\MarketplaceController;
use App\Http\Controllers\Api\Kine\PathologyController;
use App\Http\Controllers\Api\Kine\PatientGoalController as KinePatientGoalController;
use App\Http\Controllers\Api\Kine\ProfileController;
use App\Http\Controllers\Api\Kine\RewardsController;
use App\Http\Controllers\Api\Kine\SecurityController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\Patient\MilestoneController;
use App\Http\Controllers\Api\Patient\PatientAchievementController;
use App\Http\Controllers\Api\Patient\PatientCalendarController;
use App\Http\Controllers\Api\Patient\PatientDashboardController;
use App\Http\Controllers\Api\Patient\PatientExerciseSessionController;
use App\Http\Controllers\Api\Patient\PatientGoalController;
use App\Http\Controllers\Api\Patient\PatientProgramController;
use App\Http\Controllers\Api\Patient\PatientRewardController;
use App\Http\Controllers\Api\Patient\PatientStatsController;
use App\Http\Controllers\Api\Patient\ProgressController;
use App\Http\Controllers\Api\Patient\ProgressReportController;
use App\Http\Middleware\VerifyDevice;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;


// API Version 1
Route::prefix('v1')
// ->middleware(['api.version:v1'])
->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink']);
    Route::post('/verify-reset-token', [ForgotPasswordController::class, 'verifyResetToken']);
    Route::post('/reset-password', [ForgotPasswordController::class, 'resetPassword']);
    Route::post('/refresh-token', [AuthController::class, 'refreshToken']);
    Route::post('/verify-email', [AuthController::class, 'verifyEmail']);
    Route::post('/resend-otp', [AuthController::class, 'resendOtp']);
    Route::post('/check-otp-status', [AuthController::class, 'checkOtpStatus']);

    Route::middleware(['auth:sanctum'])->group(function () {

        // Auth routes
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::put('/change-password', [AuthController::class, 'changePassword']);

        // Device management
        Route::get('/check-device', [AuthController::class, 'checkDevice']);

        // Kine routes
        Route::prefix('kine')->group(function () {
            Route::prefix('patients')->group(function () {
                Route::get('/getAll', [PatientController::class, 'getPatients'])->name('patients.getAll');
                Route::get('/statistics', [PatientController::class, 'statistics'])->name('patients.statistics');
                Route::get('/active-with-programs', [PatientController::class, 'getActivePatientsWithPrograms'])
                ->name('patients.active-with-programs');
                Route::put('/{id}/pathologies', [PatientController::class, 'updatePathologies'])->name('patients.pathologies.update');
                Route::get('/{patient}/progress', [PatientController::class, 'getProgress']);
                Route::post('/{patient}/remind', [PatientController::class, 'sendReminder']);
                Route::post('/upload-medical-image', [MedicalReportController::class, 'uploadImage']);
                Route::get('/{patient}/detail', [PatientController::class, 'getPatientDetail']);
                Route::get('/{patient}/programs', [PatientController::class, 'getPatientPrograms']);
                Route::post('/{patient}/medical-report/pdf', [MedicalReportController::class, 'generatePDF']);
                Route::put('/medical-notes', [MedicalReportController::class, 'updateMedicalNotes']);
                Route::get('/{patient}/details', [PatientController::class, 'show'])->name('patients.details');
                Route::patch('/{patient}/toggle-status', [PatientController::class, 'toggleStatus']);
                Route::get('/{patient}/documents', [PatientDocumentController::class, 'index']);
                Route::post('/{patient}/documents', [PatientDocumentController::class, 'store']);
                Route::delete('/{patient}/documents/{document}', [PatientDocumentController::class, 'destroy']);
                Route::get('/{patient}/documents/{document}/download', [PatientDocumentController::class, 'download']);
                Route::prefix('/{patientId}')->group(function () {
                    Route::get('/goals', [KinePatientGoalController::class, 'index']);
                    Route::post('/goals', [KinePatientGoalController::class, 'store']);
                    Route::get('/goals/statistics', [KinePatientGoalController::class, 'statistics']);
                });
            });

             Route::prefix('pathologies')->group(function () {
                Route::get('/', [PathologyController::class, 'index'])->name('pathologies.index');
                Route::post('/', [PathologyController::class, 'store'])->name('pathologies.store');
                Route::get('/categories', [PathologyController::class, 'categories'])->name('pathologies.categories');
                Route::post('/reorder', [PathologyController::class, 'reorder'])->name('pathologies.reorder');
                Route::get('/{id}', [PathologyController::class, 'show'])->name('pathologies.show');
                Route::put('/{id}', [PathologyController::class, 'update'])->name('pathologies.update');
                Route::delete('/{id}', [PathologyController::class, 'destroy'])->name('pathologies.destroy');
                Route::patch('/{id}/toggle-status', [PathologyController::class, 'toggleStatus'])->name('pathologies.toggle-status');
            });

            Route::delete('/goals/{goalId}', [KinePatientGoalController::class, 'destroy']);


            Route::apiResource('patients', PatientController::class);

            // Calendar Routes
            Route::prefix('calendar')->group(function () {
                Route::get('/appointments', [CalendarController::class, 'appointments']);
                Route::get('/events', [CalendarController::class, 'events']);
                Route::get('/available-slots', [CalendarController::class, 'availableSlots']);
                Route::get('/statistics', [CalendarController::class, 'statistics']);
                Route::post('/appointments', [CalendarController::class, 'store']);
                Route::put('/appointments/{id}', [CalendarController::class, 'update']);
                Route::get('/cancellation-reasons', [CalendarController::class, 'getCancellationReasons']);
                Route::get('/appointments/{id}/cancellation-details', [CalendarController::class, 'getCancellationDetails']);
                Route::patch('/appointments/{id}/cancel', [CalendarController::class, 'cancelWithReason']);
                Route::post('/appointments/{id}/complete', [CalendarController::class, 'complete']);
                Route::get('/availability-settings', [AvailabilitySettingsController::class, 'index']);
                Route::put('/availability-settings', [AvailabilitySettingsController::class, 'update']);
                Route::patch('/appointments/{id}/approve', [CalendarController::class, 'approve']);
                Route::post('/appointments/{id}/report', [AppointmentReportController::class, 'store']);
                Route::post('/appointments/{id}/report/{reportId}', [AppointmentReportController::class, 'update']);
                Route::get('/appointments/{id}/report', [AppointmentReportController::class, 'show']);
            });

            // Exercise routes
            Route::apiResource('exercises', ExerciseController::class)->except(['create', 'edit']);
            Route::post('exercises/reorder', [ExerciseController::class, 'reorder']);

            //Exercise category routes
            Route::apiResource('exercise-categories', ExerciseCategoryController::class);
            Route::patch('exercise-categories/{category}/toggle-status', [ExerciseCategoryController::class, 'toggleStatus']);

            // Program routes
            Route::apiResource('programs', ProgramController::class)->except(['create', 'edit']);
            Route::get('programs/templates', [ProgramController::class, 'templates']);
            Route::post('programs/assign', [ProgramController::class, 'assign']);
            Route::get('programs/{program}', [ProgramController::class, 'show']);
            Route::patch('/programs/{program}/toggle-status', [ProgramController::class, 'toggleStatus']);

            // Session routes
            Route::post('sessions/check-in', [ExerciseSessionController::class, 'checkIn']);
            Route::get('sessions/history', [ExerciseSessionController::class, 'history']);
            Route::get('sessions/stats', [ExerciseSessionController::class, 'stats']);
            Route::get('dashboard/stats', [ExerciseSessionController::class, 'dashboardStats']);
            Route::get('exercise-sessions', [ExerciseSessionController::class, 'index']);
            Route::get('exercise-sessions/stats', [ExerciseSessionController::class, 'getSessionStats']);

            //Rewards Routes
            Route::post('/exercise-sessions/{session}/complete', [RewardsController::class, 'recordExerciseCompletion']);
            Route::get('/patients/{patient}/rewards/stats', [RewardsController::class, 'getPatientStats']);
            Route::get('/patients/{patient}/rewards/available', [RewardsController::class, 'getAvailableRewards']);

            Route::prefix('marketplace')->group(function () {
                Route::get('/categories', [MarketplaceController::class, 'getCategories']);
                Route::get('/products', [MarketplaceController::class, 'getProducts']);
                Route::get('/kine-products', [MarketplaceController::class, 'getKineProducts']);
                Route::post('/products', [MarketplaceController::class, 'storeProduct']);
                Route::post('/categories', [MarketplaceController::class, 'storeCategory']);
                Route::post('/subcategories', [MarketplaceController::class, 'storeSubcategory']);
                Route::get('/products/{id}', [MarketplaceController::class, 'getProduct']);
                Route::get('/recommendations', [MarketplaceController::class, 'getRecommendations']);
                Route::post('/recommendations', [MarketplaceController::class, 'createRecommendation']);
                Route::put('/recommendations/{id}/status', [MarketplaceController::class, 'updateRecommendationStatus']);
                Route::delete('/recommendations/{id}', [MarketplaceController::class, 'deleteRecommendation']);
                Route::get('/statistics', [MarketplaceController::class, 'getStatistics']);
                Route::get('/popular-products', [MarketplaceController::class, 'getPopularProducts']);
            });

             Route::prefix('patients/{patientId}')->group(function () {
                    // Reports
                    Route::get('/progress-reports', [App\Http\Controllers\Api\Kine\ProgressReportController::class, 'index']);
                    Route::post('/progress-reports/generate', [App\Http\Controllers\Api\Kine\ProgressReportController::class, 'generate']);
                    Route::get('/progress-reports/statistics', [App\Http\Controllers\Api\Kine\ProgressReportController::class, 'getStatistics']);
                    // Individual report
                    Route::get('/progress-reports/{reportId}', [App\Http\Controllers\Api\Kine\ProgressReportController::class, 'show']);
                    Route::put('/progress-reports/{reportId}', [App\Http\Controllers\Api\Kine\ProgressReportController::class, 'update']);
                    Route::patch('/progress-reports/{reportId}/publish', [App\Http\Controllers\Api\Kine\ProgressReportController::class, 'publish']);
                    Route::delete('/progress-reports/{reportId}', [App\Http\Controllers\Api\Kine\ProgressReportController::class, 'destroy']);
                    Route::post('/progress-reports/{reportId}/pdf', [App\Http\Controllers\Api\Kine\ProgressReportController::class, 'generatePdf']);

                    // Report requests
                    Route::get('/report-requests', [App\Http\Controllers\Api\Kine\ProgressReportController::class, 'getReportRequests']);
            });

            Route::prefix('report-requests')->group(function () {
                Route::patch('/{requestId}', [App\Http\Controllers\Api\Kine\ProgressReportController::class, 'updateReportRequest']);
            });

            Route::post('/progress-reports/generate-monthly', [App\Http\Controllers\Api\Kine\ProgressReportController::class, 'generateMonthlyReports'])
                ->middleware('throttle:1,1440');

            Route::prefix('analytics')->group(function () {
                Route::get('/overview', [AnalyticsController::class, 'getOverviewMetrics']);
                Route::get('/revenue', [AnalyticsController::class, 'getRevenueAnalytics']);
                Route::get('/patients', [AnalyticsController::class, 'getPatientMetrics']);
                Route::get('/demographics', [AnalyticsController::class, 'getPatientDemographics']);
                Route::get('/appointments', [AnalyticsController::class, 'getAppointmentAnalytics']);
                Route::get('/time-slots', [AnalyticsController::class, 'getTimeSlotEfficiency']);
                Route::get('/cancellations', [AnalyticsController::class, 'getCancellationAnalysis']);
                Route::get('/exercise-engagement', [AnalyticsController::class, 'getExerciseEngagement']);
                Route::get('/patient-performance', [AnalyticsController::class, 'getPatientPerformance']);
                Route::get('/performance-goals', [AnalyticsController::class, 'getPerformanceGoals']);
                Route::get('/pathology-distribution', [AnalyticsController::class, 'getPathologyDistribution']);
                Route::post('/export', [AnalyticsController::class, 'exportAnalyticsData']);
                Route::get('/cancellation-reasons', [AnalyticsController::class, 'getCancellationReasons']);
                Route::get('/key-insights', [AnalyticsController::class, 'getKeyInsights']);
            });

            Route::prefix('dashboard')->group(function () {
                // Main dashboard
                Route::get('/overview', [DashboardController::class, 'getDashboardOverview']);
                Route::get('/widgets', [DashboardController::class, 'getWidgetsConfig']);
                Route::post('/clear-cache', [DashboardController::class, 'clearDashboardCache']);

                // CSV Import
                Route::post('/import', [DashboardController::class, 'importPatients']);
                Route::get('/import/sample', [DashboardController::class, 'getSampleCSVFormat']);

                // Quick Actions
                Route::post('/quick-action', [DashboardController::class, 'quickAction']);

                // Notifications
                Route::get('/notifications', [DashboardController::class, 'getNotifications']);
                Route::post('/notifications/{notificationId}/read', [DashboardController::class, 'markNotificationAsRead']);
                Route::post('/notifications/read-all', [DashboardController::class, 'markAllNotificationsAsRead']);

                // Activity Feed
                Route::get('/activity-feed', [DashboardController::class, 'getActivityFeed']);
            });

            // Profile routes
            Route::prefix('profile')->group(function () {
                Route::get('/', [ProfileController::class, 'show']);
                Route::put('/basic', [ProfileController::class, 'updateBasic']);
                Route::put('/professional', [ProfileController::class, 'updateProfessional']);
                Route::post('/avatar', [ProfileController::class, 'uploadAvatar']);
            });

            // Security routes
            Route::prefix('security')->group(function () {
                Route::post('/change-password', [SecurityController::class, 'changePassword']);
                Route::get('/sessions', [SecurityController::class, 'getSessions']);
                Route::delete('/sessions/{tokenId}', [SecurityController::class, 'revokeSession']);
                Route::post('/sessions/revoke-all', [SecurityController::class, 'revokeAllSessions']);
                Route::delete('/devices/{deviceId}/sessions', [SecurityController::class, 'revokeDeviceSessions']);
                Route::put('/devices/{deviceId}/trust', [SecurityController::class, 'updateDeviceTrust']);
                Route::put('/device-settings', [SecurityController::class, 'updateDeviceSettings']);
                Route::get('/history', [SecurityController::class, 'getSecurityHistory']);
            });


        });

            Route::prefix('notifications')->group(function () {
                Route::get('/', [NotificationController::class, 'index']);
                Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
                Route::post('/{id}/read', [NotificationController::class, 'markAsRead']);
                Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
                Route::delete('/{id}', [NotificationController::class, 'destroy']);
            });


            // Patient routes
        Route::prefix('patient')->group(function () {
            //Calendar
            Route::prefix('calendar')->group(function () {
                Route::get('/events', [PatientCalendarController::class, 'events']);
                Route::get('/appointments', [PatientCalendarController::class, 'appointments']);
                Route::get('/kines', [PatientCalendarController::class, 'kines']);
                Route::get('/statistics', [PatientCalendarController::class, 'statistics']);
                Route::get('/upcoming', [PatientCalendarController::class, 'upcoming']);

                Route::get('/available-slots', [PatientCalendarController::class, 'getAvailableSlots']);
                Route::post('/appointments', [PatientCalendarController::class, 'storeAppointment']);
                Route::put('/appointments/{id}', [PatientCalendarController::class, 'updateAppointment']);

                Route::post('/appointments/{id}/cancel', [PatientCalendarController::class, 'cancelAppointment']);
                Route::post('/appointments/{id}/request-cancellation', [PatientCalendarController::class, 'requestCancellation']);

                Route::get('/cancellation-reasons', [PatientCalendarController::class, 'getCancellationReasons']);
                Route::get('/kines/{kineId}/settings', [PatientCalendarController::class, 'getKineSettings']);
                Route::get('/kines/{kineId}/availability', [PatientCalendarController::class, 'getKineAvailability']);
            });

             // Programs
            Route::get('/programs', [PatientProgramController::class, 'index']);
            Route::get('/programs/{programAssignment}', [PatientProgramController::class, 'show']);

            // Exercise sessions
            Route::prefix('sessions')->group(function () {
                Route::get('/today', [PatientExerciseSessionController::class, 'getTodaySessions']);
                Route::get('/history', [PatientExerciseSessionController::class, 'getHistory']);
                Route::get('/upcoming', [PatientExerciseSessionController::class, 'getUpcoming']);
                Route::post('/check-in', [PatientExerciseSessionController::class, 'checkIn']);
                Route::put('/{session}', [PatientExerciseSessionController::class, 'update']);
                Route::post('/{session}/complete', [PatientExerciseSessionController::class, 'markComplete']);
            });

            // Statistics and achievements
            Route::get('/stats', [PatientStatsController::class, 'getStats']);
            Route::get('/achievements', [PatientAchievementController::class, 'index']);

            // Rewards
            Route::get('/rewards', [PatientRewardController::class, 'index']);
            Route::post('/rewards/{reward}/claim', [PatientRewardController::class, 'claim']);
            Route::get('/rewards/history', [PatientRewardController::class, 'history']);

            // Exercise categories
            Route::get('/exercises/categories', [ExerciseCategoryController::class, 'index']);

            //Progress and goals
            Route::prefix('progress')->group(function () {
                // Stats and metrics
                Route::get('/stats', [ProgressController::class, 'getStats']);
                Route::get('/metrics', [ProgressController::class, 'getMetrics']);
                Route::get('/pain-data', [ProgressController::class, 'getPainData']);
                Route::get('/weekly', [ProgressController::class, 'getWeeklyProgress']);
                Route::get('/daily', [ProgressController::class, 'getDailyProgress']);
                Route::get('/comparison', [ProgressController::class, 'getComparisonData']);

                // Goals
                Route::get('/goals', [PatientGoalController::class, 'index']);
                Route::get('/goals/statistics', [PatientGoalController::class, 'statistics']);
                Route::post('/goals', [PatientGoalController::class, 'store']);
                Route::put('/goals/{id}', [PatientGoalController::class, 'update']);
                Route::put('/goals/{id}/progress', [PatientGoalController::class, 'updateProgress']);
                Route::delete('/goals/{id}', [PatientGoalController::class, 'destroy']);

                // Milestones
                Route::get('/milestones', [MilestoneController::class, 'index']);

                // Reports
                Route::prefix('reports')->group(function () {
                    Route::get('/', [ProgressReportController::class, 'index']);
                    Route::get('/{id}', [ProgressReportController::class, 'show']);
                    Route::get('/{id}/download', [ProgressReportController::class, 'downloadReport']);
                    Route::get('/statistics', [ProgressReportController::class, 'getStatistics']);


                    // Requests
                    Route::get('/requests', [ProgressReportController::class, 'getReportRequests']);
                    Route::post('/requests', [ProgressReportController::class, 'requestReport']);
                });

                    Route::get('/kines', [ProgressReportController::class, 'getPatientKines']);

            });

            // Dashboard
            Route::prefix('dashboard')->name('dashboard.')->group(function () {
                Route::get('/stats', [PatientDashboardController::class, 'getStats'])->name('stats');
                Route::get('/programs', [PatientDashboardController::class, 'getPrograms'])->name('programs');
                Route::get('/upcoming-appointments', [PatientDashboardController::class, 'getUpcomingAppointments'])->name('upcoming');
                Route::get('/progress-stats', [PatientDashboardController::class, 'getProgressStats'])->name('progress-stats');
                Route::get('/pain-data', [PatientDashboardController::class, 'getPainData'])->name('pain-data');
                Route::get('/weekly-progress', [PatientDashboardController::class, 'getWeeklyProgress'])->name('weekly-progress');
                Route::get('/quotes', [PatientDashboardController::class, 'getQuotes'])->name('quotes');
                Route::get('/emergency-contacts', [PatientDashboardController::class, 'getEmergencyContacts'])->name('emergency-contacts');

                Route::prefix('pain')->name('pain.')->group(function () {
                    Route::get('/reports', [PatientDashboardController::class, 'getPainReports'])->name('reports');
                    Route::get('/reports/{id}', [PatientDashboardController::class, 'getPainReport'])->name('show');
                    Route::post('/report', [PatientDashboardController::class, 'reportPain'])->name('report');
                    Route::get('/statistics', [PatientDashboardController::class, 'getPainStatistics'])->name('statistics');
                });

                // Quick Actions
                Route::post('/report-pain', [PatientDashboardController::class, 'reportPain'])->name('report-pain');
            });
        });

        Route::get('/notifications/preferences', [NotificationPreferenceController::class, 'show']);
        Route::put('/notifications/preferences', [NotificationPreferenceController::class, 'update']);
        Route::post('/notifications/test', [NotificationPreferenceController::class, 'test']);
        Route::get('/notifications/statistics', [NotificationPreferenceController::class, 'statistics']);

        Broadcast::routes(['middleware' => ['auth:sanctum']]);

    });

    Route::get('/health', function () {
        return response()->json([
            'status' => 'healthy',
            'timestamp' => now()->toISOString(),
            'version' => '1.0.0',
        ]);
    });
});

Route::fallback(function () {
    return response()->json([
        'error' => 'API endpoint not found. Please check the version.',
        'supported_versions' => ['v1'],
        'current_version' => 'v1',
        'documentation' => 'https://docs.hub-kine.com/api/v1',
    ], 404);
});
