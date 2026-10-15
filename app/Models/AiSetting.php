<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiSetting extends Model
{
    protected $fillable = ['provider', 'model', 'api_key'];

    protected $hidden = ['api_key'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['api_key' => 'encrypted'];
    }

    public static function shared(): ?self
    {
        return static::find(1);
    }

    public function isConfigured(): bool
    {
        return in_array($this->provider, ['openai', 'anthropic', 'gemini', 'openrouter'], true)
            && filled($this->model) && filled($this->api_key);
    }
}
