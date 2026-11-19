<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BotConversation extends Model
{
    protected $fillable = ['chatwoot_conversation_id', 'state', 'phone', 'name'];

    public function messages(): HasMany
    {
        return $this->hasMany(BotMessage::class);
    }

    public function isHandedOff(): bool
    {
        return $this->state === 'handoff';
    }
}
