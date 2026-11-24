<?php

namespace App\Support;

/**
 * Display name / colour / icon of a provider type in the legacy `providers/distribution/stats`
 * response. Values are the V1 constants, unchanged, so existing dashboards keep their look.
 * An unknown key falls back exactly like V1 did (humanized key, grey, no icon).
 */
final class LegacyTypeStyle
{
    /** @var array<string, array{0: string, 1: string, 2: string}> key => [display name, colour, icon] */
    private const STYLES = [
        'hospital' => ['Hospital', '#dc3545', 'hospital'],
        'clinic' => ['Clinic', '#0d6efd', 'clinic-medical'],
        'laboratory' => ['Laboratory', '#198754', 'flask'],
        'pharmacy' => ['Pharmacy', '#fd7e14', 'pills'],
        'radiology_center' => ['Radiology Center', '#6f42c1', 'x-ray'],
        'physical_therapy' => ['Physical Therapy', '#20c997', 'dumbbell'],
        'specialized_center' => ['Specialized Center', '#e83e8c', 'stethoscope'],
        'optics' => ['Optics', '#6c757d', 'eye'],
        'home_healthcare' => ['Home Healthcare Services', '#4a90d9', 'house-chimney-medical'],
        'audiology_hearing_aid' => ['Audiology & Hearing Aid Centers', '#9b59b6', 'ear'],
        'dental_clinic' => ['Dental Clinics & Centers', '#00a8cc', 'tooth'],
        'pain_management' => ['Pain Management Centers', '#e74c3c', 'tablets'],
        'fertility_ivf' => ['Fertility & IVF Centers', '#e91e63', 'baby'],
        'health_wellness' => ['Health & Wellness', '#2ecc71', 'heart-pulse'],
        'fitness_center' => ['Fitness Centers & Gyms', '#f39c12', 'person-running'],
        'health_club' => ['Health Clubs', '#1abc9c', 'leaf'],
        'nutrition_diet' => ['Nutrition & Diet Centers', '#27ae60', 'carrot'],
        'mental_health' => ['Mental Health & Psychological Counseling Centers', '#8e44ad', 'brain'],
        'beauty_personal_care' => ['Beauty & Personal Care', '#ff6b81', 'wand-sparkles'],
        'dermatology_clinic' => ['Dermatology Clinics', '#c0392b', 'bandage'],
        'beauty_center' => ['Beauty Centers', '#ff4757', 'star'],
        'laser_hair_removal' => ['Laser Hair Removal Centers', '#a29bfe', 'bolt'],
        'non_surgical_cosmetic' => ['Non-Surgical Cosmetic Clinics', '#fd79a8', 'droplet'],
        'skincare_center' => ['Skincare Centers', '#fab1a0', 'hand'],
        'slimming_body_contouring' => ['Slimming & Body Contouring Centers', '#00b894', 'weight-scale'],
        'hair_care' => ['Hair Care & Hair Treatment Centers', '#e17055', 'scissors'],
        'lifestyle_service' => ['Lifestyle Services', '#00cec9', 'sun'],
        'specialized_fitness' => ['Specialized Fitness Centers', '#6c5ce7', 'dumbbell'],
        'spa_wellness' => ['Spa & Wellness Centers', '#fd79a8', 'spa'],
        'mother_child_care' => ['Mother & Child Care Service', '#e84393', 'baby-carriage'],
    ];

    public static function displayName(string $key): string
    {
        return self::STYLES[$key][0] ?? ucfirst(str_replace('_', ' ', $key));
    }

    public static function color(string $key): string
    {
        return self::STYLES[$key][1] ?? '#6c757d';
    }

    public static function icon(string $key): string
    {
        return self::STYLES[$key][2] ?? 'hospital';
    }
}
