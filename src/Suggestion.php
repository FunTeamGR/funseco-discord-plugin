<?php
namespace Funseco\Discord;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $tmx_id
 * @property string $tmx_section
 * @property string $status pending|added
 * @property string $discord_author
 * @property string|null $approved_by
 * @property string|null $message_id
 */
class Suggestion extends Model
{
    protected $table = 'discord_map_suggestions';
    protected $guarded = ['id'];
}
