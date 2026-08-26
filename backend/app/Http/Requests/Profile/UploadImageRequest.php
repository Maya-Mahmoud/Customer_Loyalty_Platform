<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An uploaded picture, for an avatar or a store logo.
 *
 * The list of types is deliberately short and does not include SVG: an SVG is a
 * document that can carry script, not an image, and it cannot be re-encoded into
 * pixels the way ImageStorage re-encodes the rest. The two-megabyte cap is about
 * the upload itself; what is stored is resized well below it.
 */
class UploadImageRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
