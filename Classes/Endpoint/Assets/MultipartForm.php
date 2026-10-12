<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Assets;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * The fields and files of a multipart/form-data request, for the uploads
 *
 * neos/openapi only decodes JSON request bodies, so an operation that takes a file reads it from the request itself
 * (PHP parses multipart bodies into the request's parsed body and uploaded files) and describes it in its description:
 * the OpenAPI document has no request body for it
 */
final readonly class MultipartForm
{
    /**
     * @param array<mixed> $fields
     * @param array<mixed> $files
     */
    private function __construct(
        private array $fields,
        private array $files,
    ) {
    }

    public static function of(ServerRequestInterface $request): self
    {
        $fields = $request->getParsedBody();
        return new self(is_array($fields) ? $fields : [], $request->getUploadedFiles());
    }

    /**
     * The file, null if there is none or its upload failed (see uploadError())
     */
    public function file(string $name): ?UploadedFileInterface
    {
        $file = $this->files[$name] ?? null;
        return $file instanceof UploadedFileInterface && $file->getError() === UPLOAD_ERR_OK ? $file : null;
    }

    /**
     * Why the file is missing, for the 400
     */
    public function uploadError(string $name): string
    {
        $file = $this->files[$name] ?? null;
        return match (true) {
            !$file instanceof UploadedFileInterface, $file->getError() === UPLOAD_ERR_NO_FILE => sprintf('The request has no file "%s", send it as multipart/form-data', $name),
            in_array($file->getError(), [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) => sprintf('The file "%s" is larger than the server accepts', $name),
            default => sprintf('The upload of the file "%s" failed (PHP upload error %d)', $name, $file->getError()),
        };
    }

    /**
     * A text field, null if it is left out or empty
     */
    public function string(string $name): ?string
    {
        $value = $this->fields[$name] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A repeated text field (name[]), [] if it is left out, null if it isn't a list of texts
     *
     * @return list<string>|null
     */
    public function strings(string $name): ?array
    {
        $values = $this->fields[$name] ?? [];
        if (!is_array($values) || array_filter($values, static fn (mixed $value) => !is_string($value)) !== []) {
            return null;
        }
        return array_values($values);
    }

    /**
     * A boolean field (true, false, 1, 0), the default if it is left out, null if it is none of them
     */
    public function bool(string $name, bool $default): ?bool
    {
        $value = $this->fields[$name] ?? null;
        return match ($value) {
            null => $default,
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };
    }
}
