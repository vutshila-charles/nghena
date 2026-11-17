<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class IdempotencyService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  \Closure(): array{status: int, body: array<string, mixed>}  $operation
     * @return array{status: int, body: array<string, mixed>, replayed: bool}
     */
    public function run(User $user, string $key, array $data, \Closure $operation): array
    {
        $requestHash = hash('sha256', json_encode($this->canonicalize($data), JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($user, $key, $requestHash, $operation) {
                $entry = IdempotencyKey::query()
                    ->where('user_id', $user->id)
                    ->where('key', $key)
                    ->lockForUpdate()
                    ->first();

                if ($entry) {
                    if (! hash_equals($entry->request_hash, $requestHash)) {
                        throw new ApiException('idempotency_key_reused', 'This idempotency key was already used for a different request.', 409);
                    }

                    if ($entry->response_body === null) {
                        throw new ApiException('request_in_progress', 'A request using this idempotency key is still in progress.', 409);
                    }

                    return ['status' => $entry->response_status, 'body' => $entry->response_body, 'replayed' => true];
                }

                $entry = IdempotencyKey::query()->create([
                    'user_id' => $user->id,
                    'key' => $key,
                    'request_hash' => $requestHash,
                ]);
                $result = $operation();
                $entry->update([
                    'response_body' => $result['body'],
                    'response_status' => $result['status'],
                ]);

                return [...$result, 'replayed' => false];
            });
        } catch (QueryException $exception) {
            $entry = IdempotencyKey::query()->where('user_id', $user->id)->where('key', $key)->first();

            if (! $entry) {
                throw $exception;
            }

            if (! hash_equals($entry->request_hash, $requestHash)) {
                throw new ApiException('idempotency_key_reused', 'This idempotency key was already used for a different request.', 409);
            }

            if ($entry->response_body === null) {
                throw new ApiException('request_in_progress', 'A request using this idempotency key is still in progress.', 409);
            }

            return ['status' => $entry->response_status, 'body' => $entry->response_body, 'replayed' => true];
        }
    }

    private function canonicalize(array $data): array
    {
        ksort($data);

        foreach ($data as $key => $value) {
            if ($value instanceof UploadedFile) {
                $data[$key] = hash_file('sha256', $value->getRealPath());
            } elseif (is_array($value)) {
                $data[$key] = $this->canonicalize($value);
            }
        }

        return $data;
    }
}
