<?php

namespace Tests\Unit\Security;

use App\Http\Middleware\AuditProtectedDataRead;
use App\Models\User;
use App\Modules\Core\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class AuditProtectedDataReadTest extends TestCase
{
    public function test_successful_record_read_logs_only_ids_and_resource_metadata(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once()->withArgs(
            static function (string $message, array $context): bool {
                return $message === 'protected_record_read'
                    && $context['actor_user_id'] === 7
                    && $context['resource'] === 'contact'
                    && $context['action'] === 'view'
                    && $context['record_id'] === 42
                    && ! array_key_exists('email', $context)
                    && ! array_key_exists('name', $context);
            },
        );
        Log::shouldReceive('channel')->once()->with('personal_data_access')->andReturn($logger);

        $user = new User(['name' => 'Staff']);
        $user->setAttribute('id', 7);
        $contact = new Contact(['email' => 'private@example.test']);
        $contact->setAttribute('id', 42);
        $route = new Route('GET', '/contacts/{contact}', fn (): Response => new Response());
        $route->bind(Request::create('/contacts/42'));
        $route->setParameter('contact', $contact);

        $request = Request::create('/contacts/42', 'GET');
        $request->setUserResolver(static fn (): User => $user);
        $request->setRouteResolver(static fn (): Route => $route);

        $response = app(AuditProtectedDataRead::class)->handle(
            $request,
            static fn (): Response => new Response('ok', 200),
            'contact',
            'view',
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_contact_lookup_logs_metadata_without_search_query(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once()->withArgs(
            static fn (string $message, array $context): bool =>
                $message === 'protected_record_read'
                && $context['resource'] === 'contact'
                && $context['action'] === 'lookup'
                && $context['record_id'] === null
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'private@example.test'),
        );
        Log::shouldReceive('channel')->once()->with('personal_data_access')->andReturn($logger);

        $user = new User();
        $user->setAttribute('id', 7);
        $request = Request::create('/contacts/lookup?q=private@example.test', 'GET');
        $request->setUserResolver(static fn (): User => $user);

        app(AuditProtectedDataRead::class)->handle(
            $request,
            static fn (): Response => new Response('ok', 200),
            'contact',
            'lookup',
        );
    }

    public function test_successful_csv_export_initiation_is_audited_without_exporting_pii_to_log(): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once()->withArgs(
            static fn (string $message, array $context): bool =>
                $message === 'protected_data_export_initiated'
                && $context['resource'] === 'contact'
                && $context['action'] === 'export'
                && $context['record_id'] === null
                && ! array_key_exists('email', $context),
        );
        Log::shouldReceive('channel')->once()->with('personal_data_access')->andReturn($logger);

        $user = new User();
        $user->setAttribute('id', 8);
        $request = Request::create('/contacts/results/export', 'POST', ['search' => 'secret@example.test']);
        $request->setUserResolver(static fn (): User => $user);

        app(AuditProtectedDataRead::class)->handle(
            $request,
            static fn (): Response => new Response('export accepted', 200),
            'contact',
            'export',
        );
    }

    public function test_denied_requests_do_not_produce_access_log_entries(): void
    {
        Log::shouldReceive('channel')->never();

        $user = new User();
        $user->setAttribute('id', 7);
        $request = Request::create('/commerce/orders', 'GET');
        $request->setUserResolver(static fn (): User => $user);

        $response = app(AuditProtectedDataRead::class)->handle(
            $request,
            static fn (): Response => new Response('forbidden', 403),
            'commerce_order',
            'list',
        );

        $this->assertSame(403, $response->getStatusCode());
    }
}
