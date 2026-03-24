<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;

class UserDevice extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'device_fingerprint',
        'device_name',
        'device_type',
        'os',
        'browser',
        'user_agent',
        'ip_address',
        'location',
        'last_used_at',
        'first_used_at',
        'is_active',
        'is_trusted',
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'first_used_at' => 'datetime',
        'is_active' => 'boolean',
        'is_trusted' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tokens()
    {
        return $this->hasMany(PersonalAccessToken::class, 'device_id');
    }

    public function securityEvents()
    {
        return $this->hasMany(SecurityEvent::class);
    }

    /**
     * Parse user agent and set device info
     */
    public function parseUserAgent(?string $userAgent): self
    {
        if (!$userAgent) {
            $this->device_name = 'Unknown Device';
            $this->device_type = 'desktop';
            $this->os = 'Unknown';
            $this->browser = 'Unknown';
            return $this;
        }

        $ua = strtolower($userAgent);

        // Determine device type and name
        if (preg_match('/iphone|ipod/', $ua)) {
            $this->device_type = 'mobile';
            $this->device_name = 'iPhone';
        } elseif (preg_match('/ipad/', $ua)) {
            $this->device_type = 'tablet';
            $this->device_name = 'iPad';
        } elseif (preg_match('/android/', $ua)) {
            if (preg_match('/mobile/', $ua)) {
                $this->device_type = 'mobile';
                $this->device_name = 'Android Phone';
            } else {
                $this->device_type = 'tablet';
                $this->device_name = 'Android Tablet';
            }
        } elseif (preg_match('/windows/', $ua)) {
            $this->device_type = 'laptop';
            if (preg_match('/windows nt 10/', $ua)) $this->device_name = 'Windows 10 PC';
            elseif (preg_match('/windows nt 11/', $ua)) $this->device_name = 'Windows 11 PC';
            else $this->device_name = 'Windows PC';
        } elseif (preg_match('/macintosh|mac os x/', $ua)) {
            $this->device_type = 'laptop';
            $this->device_name = 'Mac';
        } elseif (preg_match('/linux/', $ua)) {
            $this->device_type = 'desktop';
            $this->device_name = 'Linux PC';
        } else {
            $this->device_type = 'desktop';
            $this->device_name = 'Unknown Device';
        }

        // Determine OS with version
        if (preg_match('/windows nt 10\.0/', $ua)) $this->os = 'Windows 10';
        elseif (preg_match('/windows nt 11\.0/', $ua)) $this->os = 'Windows 11';
        elseif (preg_match('/windows nt 6\.3/', $ua)) $this->os = 'Windows 8.1';
        elseif (preg_match('/windows nt 6\.2/', $ua)) $this->os = 'Windows 8';
        elseif (preg_match('/windows nt 6\.1/', $ua)) $this->os = 'Windows 7';
        elseif (preg_match('/mac os x (\d+[._]\d+[._]\d+)/', $ua, $matches)) {
            $this->os = 'macOS ' . str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/android (\d+(\.\d+)?)/', $ua, $matches)) {
            $this->os = 'Android ' . $matches[1];
        } elseif (preg_match('/iphone os (\d+_\d+)/', $ua, $matches)) {
            $this->os = 'iOS ' . str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/ipad; cpu os (\d+_\d+)/', $ua, $matches)) {
            $this->os = 'iPadOS ' . str_replace('_', '.', $matches[1]);
        } elseif (preg_match('/linux/', $ua)) $this->os = 'Linux';
        else $this->os = 'Unknown OS';

        // Determine Browser with version
        if (preg_match('/firefox\/(\d+)/', $ua, $matches)) {
            $this->browser = 'Firefox ' . $matches[1];
        } elseif (preg_match('/edg\/(\d+)/', $ua, $matches)) {
            $this->browser = 'Edge ' . $matches[1];
        } elseif (preg_match('/chrome\/(\d+)/', $ua, $matches) && !preg_match('/edg/', $ua)) {
            $this->browser = 'Chrome ' . $matches[1];
        } elseif (preg_match('/safari\/(\d+)/', $ua, $matches) && !preg_match('/chrome/', $ua)) {
            $this->browser = 'Safari ' . $matches[1];
        } elseif (preg_match('/opera|opr/', $ua)) {
            $this->browser = 'Opera';
        } elseif (preg_match('/msie|trident/', $ua)) {
            $this->browser = 'Internet Explorer';
        } else {
            $this->browser = 'Unknown Browser';
        }

        return $this;
    }

    /**
     * Generate a consistent device fingerprint
     */
    public static function generateFingerprint(Request $request): string
    {
        $components = [
            'user_agent' => $request->userAgent(),
            'accept_language' => $request->header('Accept-Language'),
            'accept_encoding' => $request->header('Accept-Encoding'),
            'platform' => $request->header('Sec-Ch-UA-Platform'),
            'mobile' => $request->header('Sec-Ch-UA-Mobile'),
        ];

        // Add a secret salt to prevent collisions
        $salt = config('app.device_fingerprint_salt', 'lehubkine_salt_2024');

        return hash('sha256', json_encode($components) . $salt);
    }

    /**
     * Check if this is likely the same device based on fingerprint
     */
    public function isSameDevice(string $fingerprint): bool
    {
        return $this->device_fingerprint === $fingerprint;
    }

    /**
     * Update location from IP
     */
    public function updateLocation(): self
    {
        if (!$this->ip_address || $this->ip_address === '127.0.0.1' || $this->ip_address === '::1') {
            $this->location = 'Localhost';
            return $this;
        }

        try {
            // Using ip-api.com (free, no API key required)
            $response = @file_get_contents("http://ip-api.com/json/{$this->ip_address}");
            if ($response) {
                $data = json_decode($response, true);
                if ($data && $data['status'] === 'success') {
                    $city = $data['city'] ?? '';
                    $country = $data['country'] ?? 'Morocco';

                    if ($country === 'Morocco' || $country === 'Maroc') {
                        $this->location = $city ?: 'Maroc';
                    } else {
                        $this->location = $city ? "{$city}, {$country}" : $country;
                    }
                } else {
                    $this->location = 'Maroc';
                }
            } else {
                $this->location = 'Maroc';
            }
        } catch (\Exception $e) {
            \Log::error('Location lookup failed: ' . $e->getMessage());
            $this->location = 'Maroc';
        }

        return $this;
    }
}
