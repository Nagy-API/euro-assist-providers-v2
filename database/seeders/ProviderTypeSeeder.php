<?php

namespace Database\Seeders;

use App\Models\ProviderType;
use Illuminate\Database\Seeder;

/**
 * The 30 provider type keys of the frozen legacy `types` vocabulary (provisional launch
 * vocabulary, decision D3). Labels are business vocabulary, not provider records.
 * Idempotent: safe to run repeatedly.
 */
class ProviderTypeSeeder extends Seeder
{
    /** @var array<string, array{0: string, 1: string}> key => [English, Arabic] */
    public const TYPES = [
        'hospital' => ['Hospital', 'مستشفى'],
        'clinic' => ['Clinic', 'عيادة'],
        'laboratory' => ['Laboratory', 'معمل تحاليل'],
        'pharmacy' => ['Pharmacy', 'صيدلية'],
        'radiology_center' => ['Radiology Center', 'مركز أشعة'],
        'physical_therapy' => ['Physical Therapy Center', 'مركز علاج طبيعي'],
        'specialized_center' => ['Specialized Center', 'مركز متخصص'],
        'optics' => ['Optics', 'بصريات'],
        'home_healthcare' => ['Home Healthcare Services', 'خدمات الرعاية الصحية المنزلية'],
        'audiology_hearing_aid' => ['Audiology & Hearing Aid Centers', 'مراكز السمعيات والأجهزة السمعية'],
        'dental_clinic' => ['Dental Clinics & Centers', 'عيادات ومراكز الأسنان'],
        'pain_management' => ['Pain Management Centers', 'مراكز علاج الألم'],
        'fertility_ivf' => ['Fertility & IVF Centers', 'مراكز الخصوبة وأطفال الأنابيب'],
        'health_wellness' => ['Health & Wellness', 'الصحة والعافية'],
        'fitness_center' => ['Fitness Centers & Gyms', 'الأندية الرياضية'],
        'health_club' => ['Health Clubs', 'النوادي الصحية'],
        'nutrition_diet' => ['Nutrition & Diet Centers', 'مراكز التغذية العلاجية'],
        'mental_health' => ['Mental Health & Counseling Centers', 'مراكز الصحة النفسية والاستشارات النفسية'],
        'beauty_personal_care' => ['Beauty & Personal Care', 'التجميل والعناية الشخصية'],
        'dermatology_clinic' => ['Dermatology Clinics', 'عيادات الجلدية'],
        'beauty_center' => ['Beauty Centers', 'مراكز التجميل'],
        'laser_hair_removal' => ['Laser Hair Removal Centers', 'مراكز إزالة الشعر بالليزر'],
        'non_surgical_cosmetic' => ['Non-Surgical Cosmetic Clinics', 'عيادات التجميل غير الجراحي'],
        'skincare_center' => ['Skincare Centers', 'مراكز العناية بالبشرة'],
        'slimming_body_contouring' => ['Slimming & Body Contouring Centers', 'مراكز التخسيس ونحت القوام'],
        'hair_care' => ['Hair Care & Treatment Centers', 'مراكز العناية بالشعر'],
        'lifestyle_service' => ['Lifestyle Services', 'خدمات نمط الحياة'],
        'specialized_fitness' => ['Specialized Fitness Centers', 'مراكز اللياقة البدنية المتخصصة'],
        'spa_wellness' => ['Spa & Wellness Centers', 'مراكز السبا والاسترخاء'],
        'mother_child_care' => ['Mother & Child Care Services', 'خدمات العناية بالأم والطفل'],
    ];

    public function run(): void
    {
        $order = 0;
        foreach (self::TYPES as $key => [$en, $ar]) {
            $type = ProviderType::query()->updateOrCreate(['key' => $key], ['sort_order' => ++$order]);
            $type->translations()->updateOrCreate(['locale' => 'en'], ['name' => $en]);
            $type->translations()->updateOrCreate(['locale' => 'ar'], ['name' => $ar]);
        }
    }
}
