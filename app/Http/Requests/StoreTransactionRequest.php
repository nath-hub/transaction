<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTransactionRequest extends FormRequest
{

    public function prepareForValidation()
    {
        $allowed = array_keys($this->rules());
        $allowedExtra = ['auth_user_id', 'auth_permissions', 'auth_environment', 'auth_key_id', 'auth_verified_at'];

        $extraFields = collect($this->all())
            ->keys()
            ->diff(array_merge($allowed, $allowedExtra));

        if ($extraFields->isNotEmpty()) {
            abort(422, 'Champs non autorisés : ' . $extraFields->implode(', '));
        }

    }


    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $method = $this->method();
        if ($method === 'PUT') {
            return [

                'entreprise_id' => 'required|uuid|exists:entreprises,id',
                'operator_code' => 'required|string|exists:operators,code',
                'transaction_type' => 'required|in:deposit,withdrawal',
                'amount' => 'required|numeric|min:0.01|max:999999.99',
                'customer_phone' => 'required|string|max:20',
                'customer_name' => 'nullable|string|max:100',
                'metadata' => 'nullable|array'

            ];
        }
        return [
            'entreprise_id' => 'required|uuid|exists:entreprises,id',
            'operator_code' => 'required|string|exists:operators,code',
            'transaction_type' => 'required|in:deposit,withdrawal',
            'amount' => 'required|numeric|min:0.01|max:999999.99',
            'customer_phone' => 'required|string|max:20',
            'customer_name' => 'nullable|string|max:100',
            'metadata' => 'nullable|array'
        ];
    }

    public function messages(): array
    {
        return [
            'entreprise_id.required' => 'L\'ID entreprise est obligatoire',
            'transaction_type.in' => 'Le type doit être deposit ou withdrawal',
            'amount.min' => 'Le montant minimum est 1',
            'currency_code.size' => 'Le code devise doit faire 3 caractères',
            'customer_phone.required' => 'Le téléphone client est obligatoire'
        ];
    }
}
