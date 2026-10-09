<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Centre extends Model
{
    protected static function booted(): void
    {
        static::created(function (self $centre) {
            if (! \Illuminate\Support\Facades\Schema::hasTable('appointment_statuses')) return;
            $now=now();
            foreach ([['pending','نوبت','#ffffff',true,false,true],['arrived','آمد','#16a34a',false,false,true],
                ['no_show','نیامد','#111827',false,true,false],['cancelled','کنسل کرد','#dc2626',false,true,false]] as $order=>[$slug,$name,$color,$initial,$final,$blocks]) {
                \Illuminate\Support\Facades\DB::table('appointment_statuses')->insertOrIgnore([
                    'centre_id'=>$centre->id,'slug'=>$slug,'name'=>$name,'indicator_color'=>$color,
                    'text_color'=>'#111827','sort_order'=>$order,'is_initial'=>$initial,'is_final'=>$final,
                    'blocks_slot'=>$blocks,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now]);
            }
        });
    }
    protected $fillable = ['name', 'code', 'is_active', 'phone', 'email', 'address', 'description', 'timezone', 'settings'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'settings' => 'array'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(CentreBranch::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function cases(): HasMany
    {
        return $this->hasMany(CounsellingCase::class);
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRoleCentre::class);
    }

    public function paymentTransactions(): HasMany { return $this->hasMany(PaymentTransaction::class); }
    public function cashRegisterSessions(): HasMany { return $this->hasMany(CashRegisterSession::class); }
}
