<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = ['key', 'value', 'previous_value', 'group'];

    // Stored encrypted (App\Support\Secrets) and never sent back, not even to an admin: the screen
    // is told whether one is set, and an empty field keeps it
    public const SECRETS = ['smtp_password'];

    public function toArray()
    {
        $array = parent::toArray();
        if (in_array($this->key, self::SECRETS, true)) {
            $array['is_set'] = filled($this->value);
            $array['value'] = '';
            $array['previous_value'] = null;
        }

        return $array;
    }
}
