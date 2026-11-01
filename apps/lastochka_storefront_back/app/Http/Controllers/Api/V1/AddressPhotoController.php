<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Address\DeleteAddressPhotoAction;
use App\Actions\Address\UploadAddressPhotoAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Address\UploadPhotoRequest;
use App\Http\Resources\Api\V1\AddressResource;
use App\Models\Address;
use App\Models\AddressPhoto;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

#[Group('Addresses', weight: 1)]
final class AddressPhotoController extends Controller
{
    /**
     * Загрузить фотографии адреса-объекта (1–6 файлов за запрос, jpg/png, до 5 МБ каждый, суммарно не более 6 на адрес).
     * Возвращает 201 с обновлённым AddressResource, фотографии лежат в objectData.photos.
     * Доступно только для адреса с is_object=true (иначе 422) и при включённом модуле addresses.objects (иначе 404 от middleware).
     */
    #[\Dedoc\Scramble\Attributes\Response(status: 201, description: 'Фотографии загружены, возвращён адрес с обновлённым objectData.photos')]
    public function store(UploadPhotoRequest $request, Address $address, UploadAddressPhotoAction $action): JsonResponse
    {
        Gate::authorize('update', $address);

        abort_unless($address->is_object, 422, 'Адрес не является объектом');

        $existing = $address->photos()->count();
        $incoming = count((array) $request->file('photos'));
        abort_if($existing + $incoming > 6, 422, 'Превышен лимит 6 фото на адрес');

        $action->execute($address, (array) $request->file('photos'));

        return (new AddressResource($address->fresh('photos')))->response()->setStatusCode(201);
    }

    /**
     * Удалить фотографию адреса-объекта (soft-delete записи + физическое удаление файла с диска). 404 если фото не принадлежит указанному адресу.
     */
    public function destroy(Address $address, AddressPhoto $photo, DeleteAddressPhotoAction $action): Response
    {
        Gate::authorize('update', $address);

        abort_unless($photo->address_id === $address->id, 404);

        $action->execute($photo);

        return response()->noContent();
    }
}
