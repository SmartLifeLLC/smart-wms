<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base model for all WMS tables
 * All WMS models should extend this class to use the sakemaru database connection
 */
abstract class WmsModel extends Model
{
    protected $connection = 'sakemaru';
}
