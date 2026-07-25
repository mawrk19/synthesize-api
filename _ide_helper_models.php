<?php

// @formatter:off
// phpcs:ignoreFile
/**
 * A helper file for your Eloquent Models
 * Copy the phpDocs from this file to the correct Model,
 * And remove them from this file, to prevent double declarations.
 *
 * @author Barry vd. Heuvel <barryvdh@gmail.com>
 */


namespace App\Modules\Iam\Models{
/**
 * @property int $id
 * @property string $first_name
 * @property string|null $middle_name
 * @property string $last_name
 * @property string $username
 * @property string $email
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $remember_token
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Notifications\DatabaseNotificationCollection<int, \Illuminate\Notifications\DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @method static \App\Modules\Iam\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModel newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModel newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModel query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModel whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModel whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModel whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModel whereFirstName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModel whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModel whereLastName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModel whereMiddleName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModel wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModel whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModel whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserModel whereUsername($value)
 */
	class UserModel extends \Eloquent {}
}

