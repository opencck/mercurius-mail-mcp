<?php

declare(strict_types=1);

namespace Mercurius\MailMcp;

use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Psr\Log\LoggerInterface;
use SensitiveParameter;
use Throwable;

/**
 * The HTTP side: POST /mcp with a bearer token, JSON in and out (Streamable HTTP without a stream); GET /health.
 */
final class Server {
    /** a letter with files up to 25 MB, in base64 */
    private const BODY_MAX = 36 * 1024 * 1024;

    public function __construct(
        private readonly McpHandler $handler,
        #[SensitiveParameter] private readonly string $token,
        private readonly LoggerInterface $logger,
    ) {}

    public function respond(Request $request): Response {
        $path = $request->getUri()->getPath();
        if ($path === '/health') {
            return new Response(HttpStatus::OK, ['content-type' => 'application/json'], '{"status":"ok"}');
        }
        if ($path !== '/mcp' || $request->getMethod() !== 'POST') {
            return new Response(HttpStatus::NOT_FOUND, [], '');
        }
        if (!hash_equals('Bearer ' . $this->token, trim($request->getHeader('authorization') ?? ''))) {
            return new Response(HttpStatus::UNAUTHORIZED, [], '');
        }
        try {
            $request->getBody()->increaseSizeLimit(self::BODY_MAX);
            /** @var mixed $data */
            $data = json_decode($request->getBody()->buffer(limit: self::BODY_MAX), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return new Response(HttpStatus::BAD_REQUEST, [], '');
        }
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = $values[0] ?? '';
        }
        $answer = is_array($data) ? $this->handler->handle($headers, $data) : null;
        if ($answer === null) {
            return new Response(HttpStatus::ACCEPTED, [], '');
        }
        return new Response(HttpStatus::OK, ['content-type' => 'application/json'], json_encode($answer, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param int<1, 65535> $port */
    public function start(string $host, int $port): SocketHttpServer {
        $server = SocketHttpServer::createForDirectAccess($this->logger);
        $server->expose(new InternetAddress($host, $port));
        $server->start(new ClosureRequestHandler($this->respond(...)), new DefaultErrorHandler());
        return $server;
    }
}
