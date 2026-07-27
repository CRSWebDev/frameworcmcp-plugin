<?php namespace CRSCompany\FrameworCMcp\Classes;

use Exception;

/**
 * ApiException carries an HTTP status and a Laravel-shaped validation error bag.
 *
 * The MCP client reads `json.errors` when a response is not ok (see
 * frameworc-mcp src/api-client.ts), so keeping the standard
 * `{message, errors}` shape gives useful chat-side messages for free.
 */
class ApiException extends Exception
{
    /**
     * @var int status HTTP status code to return.
     */
    protected $status;

    /**
     * @var array errors keyed by field path, each holding a list of messages.
     */
    protected $errors;

    public function __construct(string $message, int $status = 422, array $errors = [])
    {
        parent::__construct($message);

        $this->status = $status;
        $this->errors = $errors;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * toResponse renders the exception as JSON.
     */
    public function toResponse()
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => $this->errors,
        ], $this->status);
    }

    /**
     * invalid builds a 422 from a field => message map.
     */
    public static function invalid(array $errors, string $message = 'The given data was invalid.'): self
    {
        $normalised = [];
        foreach ($errors as $field => $messages) {
            $normalised[$field] = is_array($messages) ? array_values($messages) : [$messages];
        }

        return new self($message, 422, $normalised);
    }

    public static function notFound(string $message = 'Not found.'): self
    {
        return new self($message, 404);
    }
}
