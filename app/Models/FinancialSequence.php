<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialSequence extends Model
{
    protected $fillable = ['centre_id', 'sequence_key', 'sequence_year', 'last_number'];
}
