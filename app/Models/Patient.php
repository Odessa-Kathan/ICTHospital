<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Patient extends Model
{
    use HasFactory;

    protected $table = 'patient';
    public $timestamps = false;

    protected $fillable = [
        'img_url',
        'name',
        'email',
        'doctor',
        'address',
        'phone',
        'sex',
        'birthdate',
        'birthdate_estimated',
        'age',
        'bloodgroup',
        'ion_user_id',
        'patient_id',
        'add_date',
        'registration_time',
        'how_added',
        'father_name',
        'patient_type_id',
        'cnic',
        'guardian_relation',
        'guardian_mrn',
        'mlc',
        'old_mrn',
        'employee_no',
        'alt_phone',
        'city',
    ];

    protected $casts = ['birthdate_estimated' => 'boolean'];

    public function getCurrentAgeAttribute(): string
    {
        if ($this->birthdate) {
            try {
                $date = \Carbon\Carbon::createFromFormat('!Y-m-d', (string) $this->birthdate);
                if ($date && $date->toDateString() === $this->birthdate && $date->lessThanOrEqualTo(today())) {
                    return (string) (int) $date->age;
                }
            } catch (\Throwable $e) {
                // Some legacy imports contain text rather than a calendar date.
            }
        }

        return (string) ($this->attributes['age'] ?? '');
    }
}
