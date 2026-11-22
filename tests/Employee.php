<?php

namespace ContractLayer\EContract\Tests;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
