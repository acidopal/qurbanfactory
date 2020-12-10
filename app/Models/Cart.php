<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    use HasFactory;

    protected $table = 'cart';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $fillable = [
		'id_cart', 'id_user', 'id_product', 'quantity', 
    ];

    public function getUser()
    {
        return $this->hasOne('App\Models\User', 'id_user', 'id_user');
    }

    public function getProduct()
    {
        return $this->hasOne('App\Models\Product', 'id_product', 'id_product');
    }
}
