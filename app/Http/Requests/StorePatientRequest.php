<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientRequest extends FormRequest
{
    public function authorize()
    {
        return auth()->check() && auth()->user()->role === 'kine';
    }

    public function rules()
    {
        return [
            // Required fields
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'date_of_birth' => 'required|date',
            'gender' => 'required|in:male,female,other',

            // Optional fields
            'email' => 'nullable|email|max:255|unique:users,email',
            'height_cm' => 'nullable|integer|min:50|max:250',
            'weight_kg' => 'nullable|numeric|min:20|max:300',
            'medical_notes' => 'nullable|string',
            'address' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:10',
            'country' => 'nullable|string|max:100',

            // Patient profile fields
            'preferred_language' => 'nullable|in:fr,en,ar',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:20',
            'preferred_contact_method' => 'nullable|in:email,phone,sms',
            'email_notifications' => 'nullable|boolean',
            'push_notifications' => 'nullable|boolean',
            'sms_notifications' => 'nullable|boolean',

            // Pathologies
            'pathologies' => 'nullable|array',
            'pathologies.*.id' => 'required|exists:pathologies,id',
            'pathologies.*.diagnosed_date' => 'nullable|date',
            'pathologies.*.notes' => 'nullable|string|max:500',
        ];
    }

    public function messages()
    {
        return [
            'first_name.required' => 'Le prénom est requis.',
            'last_name.required' => 'Le nom est requis.',
            'phone.required' => 'Le numéro de téléphone est requis.',
            'date_of_birth.required' => 'La date de naissance est requise.',
            'gender.required' => 'Le genre est requis.',
            'email.email' => 'Veuillez saisir une adresse email valide.',
            'email.unique' => 'Cette adresse email est déjà utilisée.',
            'height_cm.min' => 'La taille doit être d\'au moins 50 cm.',
            'height_cm.max' => 'La taille ne peut pas dépasser 250 cm.',
            'weight_kg.min' => 'Le poids doit être d\'au moins 20 kg.',
            'weight_kg.max' => 'Le poids ne peut pas dépasser 300 kg.',
            'preferred_language.in' => 'La langue préférée doit être français, anglais ou arabe.',
            'preferred_contact_method.in' => 'La méthode de contact préférée doit être email, téléphone ou SMS.',
            'pathologies.*.id.exists' => 'La pathologie sélectionnée n\'existe pas.',
            'pathologies.*.diagnosed_date.date' => 'La date de diagnostic doit être une date valide.',
            'pathologies.*.notes.max' => 'Les notes ne peuvent pas dépasser 500 caractères.',
        ];
    }
}
