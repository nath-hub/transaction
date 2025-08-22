<?php

namespace App\Models;

use App\Jobs\CheckTransactionStatusJobs;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * @OA\Schema(
 *     schema="Transaction",
 *     type="object",
 *     @OA\Property(property="id", type="string", format="uuid"),
 *     @OA\Property(property="entreprise_id", type="string", format="uuid"),
 *     @OA\Property(property="wallet_id", type="string", format="uuid"),
 *     @OA\Property(property="operator_id", type="string", format="uuid"),
 *     @OA\Property(property="user_id", type="string", format="uuid", nullable=true),
 *     @OA\Property(property="transaction_type", type="string", enum={"deposit", "withdrawal"}),
 *     @OA\Property(property="amount", type="number", format="decimal"),
 *     @OA\Property(property="currency_code", type="string"),
 *     @OA\Property(property="webhook_url", type="string"),
 *     @OA\Property(property="operator_commission", type="number", format="decimal"),
 *     @OA\Property(property="internal_commission", type="number", format="decimal"),
 *     @OA\Property(property="net_amount", type="number", format="decimal"),
 *     @OA\Property(property="status", type="string", enum={"FAILED", "CANCELLED", "EXPIRED", "SUCCESSFULL"}),
 *     @OA\Property(property="operator_status", type="string", nullable=true),
 *     @OA\Property(property="operator_transaction_id", type="string", nullable=true),
 *     @OA\Property(property="customer_phone", type="string"),
 *     @OA\Property(property="customer_name", type="string", nullable=true),
 *     @OA\Property(property="initiated_at", type="string", format="datetime"),
 *     @OA\Property(property="completed_at", type="string", format="datetime", nullable=true),
 *     @OA\Property(property="api_key_used", type="string", nullable=true),
 *     @OA\Property(property="ip_address", type="string", nullable=true),
 *     @OA\Property(property="user_agent", type="string", nullable=true),
 *     @OA\Property(property="metadata", type="object", nullable=true),
 *     @OA\Property(property="failure_reason", type="string", nullable=true),
 *     @OA\Property(property="created_at", type="string", format="datetime"),
 *     @OA\Property(property="updated_at", type="string", format="datetime")
 * )
 */
class Transaction extends Model
{
    use HasFactory;



    public $incrementing = false;
    protected $keyType = 'string';

    protected $guarded = [
        'id'
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }

            // if ($model->status === 'PENDING') {
            //     // Dispatcher le job de vérification avec un délai initial
            //     CheckTransactionStatusJobs::dispatch($model->id)
            //         ->delay(now()->addMinutes(2)); // Première vérification après 2 minutes
            // }
        });
    }


    public function wallet()
    {
        return $this->belongsTo(CompanyWallet::class, 'wallet_id');
    }


    // Scopes
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByCustomer($query, $phone)
    {
        return $query->where('customer_phone', $phone);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('transaction_type', $type);
    }

  
}
