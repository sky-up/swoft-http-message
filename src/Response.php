<?php declare(strict_types=1);

namespace Swoft\Http\Message;

use Swoft\Bean\Annotation\Mapping\Bean;
use Swoft\Bean\Container;
use Swoft\Http\Message\Concern\MessageTrait;
use Swoft\Http\Message\Contract\ResponseFormatterInterface;
use Swoft\Http\Message\Contract\ResponseInterface;
use Swoft\Http\Message\Stream\Stream;
use Swoole\Http\Response as CoResponse;

/**
 * Class Response
 *
 * @since 2.0
 * @Bean(name="httpResponse", scope=Bean::PROTOTYPE)
 */
class Response implements ResponseInterface
{
    use MessageTrait;

    /**
     * @var string
     */
    protected $reasonPhrase = '';

    /**
     * @var int
     */
    protected $statusCode = 200;

    /**
     * @var string
     */
    protected $charset = 'utf-8';

    /**
     * @var array
     */
    protected $attributes = [];

    /**
     * Original response data. When this is not null, it will be converted into stream content
     *
     * @var mixed
     */
    protected $data;

    /**
     * Exception
     *
     * @var \Throwable|null
     */
    protected $exception;

    /**
     * Coroutine response
     *
     * @var CoResponse
     */
    protected $coResponse;

    /**
     * Default format
     *
     * @var string
     */
    protected $format = self::FORMAT_JSON;

    /**
     * All formatters
     *
     * @var array
     *
     * @example
     * [
     *     Response::FORMAT_JSON => new ResponseFormatterInterface,
     *     Response::FORMAT_XML => new ResponseFormatterInterface,
     * ]
     */
    public $formatters = [];

    /**
     * Cookie
     *
     * @var array
     */
    protected $cookies = [];

    /**
     * Create response replace of constructor
     *
     * @param CoResponse $coResponse
     *
     * @return static|Response
     */
    public static function new(CoResponse $coResponse): self
    {
        $self = Container::$instance->getPrototype('httpResponse');
        // $self = \bean('httpResponse');
        /** @var Response $self */
        $self->coResponse = $coResponse;

        return $self;
    }

    /**
     * Redirect to a URL
     *
     * @param string $url
     * @param int    $status
     *
     * @return static
     */
    public function redirect($url, int $status = 302): self
    {
        $response = $this;
        $response = $response->withAddedHeader('Location', (string)$url)->withStatus($status);

        return $response;
    }

    /** @var string */
    private $filePath = '';

    /** @var string */
    private $fileType = '';

    /**
     * @param string $filePath like '/path/to/some.jpg'
     * @param string $contentType like 'image/jpeg'
     * @return $this
     */
    public function file(string $filePath, string $contentType): self
    {
        $this->filePath = $filePath;
        $this->fileType = $contentType;
        return $this;
    }

    /**
     * Send response
     *
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function send(): void
    {
        // Is send file
        if ($this->filePath) {
            $this->coResponse->header('Content-Type', $this->fileType);
            $this->coResponse->sendfile($this->filePath);
            return;
        }

        // Prepare and send
        $this->quickSend($this->prepare());
    }

    /**
     * Quick send response
     *
     * @param self|null $response
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function quickSend(Response $response = null): void
    {
        $response = $response ?: $this;

        // Write Headers to co response
        foreach ($response->getHeaders() as $key => $value) {
            $this->coResponse->header($key, \implode(';', $value));
        }

        // TODO ... write cookie

        // Set status code
        $this->coResponse->status($response->getStatusCode());

        // Set body
        $content = $response->getBody()->getContents();
        $this->coResponse->end($content);
    }

    /**
     * Prepare response
     *
     * @return Response
     */
    private function prepare(): Response
    {
        $formatter = $this->formatters[$this->format] ?? null;

        if ($formatter && $formatter instanceof ResponseFormatterInterface) {
            return $formatter->format($this);
        }

        return $this;
    }

    /**
     * Return new response instance with content
     *
     * @param $content
     *
     * @return static
     * @throws \ReflectionException
     * @throws \Swoft\Bean\Exception\ContainerException
     */
    public function withContent($content): Response
    {
        if ($this->stream) {
            return $this;
        }

        $new = clone $this;

        $new->stream = Stream::new($content);
        return $new;
    }

    /**
     * @return null|\Throwable
     */
    public function getException(): ?\Throwable
    {
        return $this->exception;
    }

    /**
     * @param \Throwable $exception
     *
     * @return $this
     */
    public function setException(\Throwable $exception): self
    {
        $this->exception = $exception;
        return $this;
    }

    /**
     * @return CoResponse
     */
    public function getCoResponse(): CoResponse
    {
        return $this->coResponse;
    }

    /**
     * @param CoResponse $coResponse
     *
     * @return $this
     */
    public function setCoResponse(CoResponse $coResponse): self
    {
        $this->coResponse = $coResponse;
        return $this;
    }

    /**
     * @param string $format
     */
    public function setFormat(string $format): void
    {
        $this->format = $format;
    }

    /**
     * Retrieve attributes derived from the request.
     * The request "attributes" may be used to allow injection of any
     * parameters derived from the request: e.g., the results of path
     * match operations; the results of decrypting cookies; the results of
     * deserializing non-form-encoded message bodies; etc. Attributes
     * will be application and request specific, and CAN be mutable.
     *
     * @return array Attributes derived from the request.
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Retrieve a single derived request attribute.
     * Retrieves a single derived request attribute as described in
     * getAttributes(). If the attribute has not been previously set, returns
     * the default value as provided.
     * This method obviates the need for a hasAttribute() method, as it allows
     * specifying a default value to return if the attribute is not found.
     *
     * @see getAttributes()
     *
     * @param string $name The attribute name.
     * @param mixed  $default Default value to return if the attribute does not exist.
     *
     * @return mixed
     */
    public function getAttribute($name, $default = null)
    {
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    /**
     * Return an instance with the specified derived request attribute.
     * This method allows setting a single derived request attribute as
     * described in getAttributes().
     * This method MUST be implemented in such a way as to retain the
     * immutability of the message, and MUST return an instance that has the
     * updated attribute.
     *
     * @see getAttributes()
     *
     * @param string $name The attribute name.
     * @param mixed  $value The value of the attribute.
     *
     * @return static|self
     */
    public function withAttribute($name, $value)
    {
        $clone                    = clone $this;
        $clone->attributes[$name] = $value;
        return $clone;
    }

    /**
     * Return instance with the specified data
     *
     * @param mixed $data
     *
     * @return static
     */
    public function withData($data)
    {
        $clone = clone $this;

        $clone->data = $data;
        return $clone;
    }

    /**
     * Gets the response status code.
     *
     * The status code is a 3-digit integer result code of the server's attempt
     * to understand and satisfy the request.
     *
     * @return int Status code.
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @inheritdoc
     * @param int    $code The 3-digit integer result code to set.
     * @param string $reasonPhrase The reason phrase to use with the
     *                             provided status code; if none is provided, implementations MAY
     *                             use the defaults as suggested in the HTTP specification.
     *
     * @return static|self
     * @throws \InvalidArgumentException For invalid status code arguments.
     */
    public function withStatus($code, $reasonPhrase = '')
    {
        $new             = clone $this;
        $new->statusCode = (int)$code;

        if ($reasonPhrase === '' && isset(self::PHRASES[$new->statusCode])) {
            $reasonPhrase = self::PHRASES[$new->statusCode];
        }

        $new->reasonPhrase = $reasonPhrase;
        return $new;
    }

    /**
     * Return an instance with the specified charset content type.
     *
     * @param $charset
     *
     * @return static|self
     * @throws \InvalidArgumentException
     */
    public function withCharset($charset): self
    {
        return $this->withAddedHeader('Content-Type', sprintf('charset=%s', $charset));
    }

    /**
     * @inheritdoc
     *
     * @link http://tools.ietf.org/html/rfc7231#section-6
     * @link http://www.iana.org/assignments/http-status-codes/http-status-codes.xhtml
     * @return string Reason phrase; must return an empty string if none present.
     */
    public function getReasonPhrase(): string
    {
        return $this->reasonPhrase;
    }

    /**
     * @return mixed
     */
    public function getData()
    {
        return $this->data;
    }

    /**
     * @return string
     */
    public function getCharset(): string
    {
        return $this->charset;
    }

    /**
     * @param string $charset
     */
    public function setCharset(string $charset): void
    {
        $this->charset = $charset;
    }
}
