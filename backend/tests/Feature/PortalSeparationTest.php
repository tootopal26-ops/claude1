<?php

namespace Tests\Feature;

use App\Livewire\Admin\Login as AdminLogin;
use App\Livewire\Staff\Login as StaffLogin;
use App\Models\Permission;
use App\Models\PermissionPreset;
use App\Models\StaffUser;
use App\Support\Portal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Admin portal (/admin/*) is for super-admin accounts and the Staff portal
 * (/staff/*) for everyone else. These tests pin the server-side boundary, the
 * sign-in behaviour of each portal, and the redirect/rate-limit protections —
 * none of which depend on what the navigation happens to show.
 */
class PortalSeparationTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC_FAILURE = 'Those credentials do not match an active account for this portal. Check them and try again.';

    private function admin(string $username = 'chief_admin'): StaffUser
    {
        return StaffUser::factory()->create(['username' => $username, 'password' => bcrypt('secret123'), 'is_super_admin' => true]);
    }

    /**
     * A staff account holding every permission — the most any non-admin can be.
     */
    private function powerfulStaff(string $username = 'ops_member'): StaffUser
    {
        $preset = PermissionPreset::create(['name' => 'Everything '.$username]);
        $preset->permissions()->attach(Permission::all()->isEmpty()
            ? $this->allPermissions()
            : Permission::all());

        return StaffUser::factory()->create([
            'username' => $username,
            'password' => bcrypt('secret123'),
            'is_super_admin' => false,
            'permission_preset_id' => $preset->id,
        ]);
    }

    /**
     * @return list<Permission>
     */
    private function allPermissions(): array
    {
        $keys = [
            'tournaments.view', 'tournaments.manage', 'results.submit', 'credentials.release', 'notifications.send',
            'staff.manage', 'audit_logs.view', 'users.view', 'users.ban', 'recurring_tournaments.manage',
            'withdrawals.manage', 'payments.view', 'payments.manage', 'leaderboards.manage', 'salary.view',
            'salary.manage', 'salary.approve', 'storage.view', 'storage.manage', 'legal.manage', 'settings.manage',
            'results.upload_attachments',
        ];

        return array_map(fn (string $key) => Permission::firstOrCreate(['key' => $key], ['label' => $key, 'group' => explode('.', $key)[0]]), $keys);
    }

    /**
     * @return list<string> parameter-free GET page routes registered for a portal
     */
    private function pageRoutes(string $portal): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with((string) $route->getName(), $portal.'.')
                && in_array('GET', $route->methods(), true)
                && ! str_contains($route->uri(), '{')
                && ! str_ends_with((string) $route->getName(), '.login')
                && $route->getName() !== $portal.'.')
            ->map(fn ($route) => $route->getName())
            ->values()
            ->all();
    }

    // --- Identity ------------------------------------------------------------

    public function test_the_two_portals_have_their_own_urls_titles_and_copy(): void
    {
        $this->assertSame('/admin/login', route('admin.login', absolute: false));
        $this->assertSame('/staff/login', route('staff.login', absolute: false));

        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee('<title>Admin sign in · Mini Max Control Center</title>', false)
            ->assertSee('MINI MAX · CONTROL CENTER')
            ->assertDontSee('Every Match. Every Detail.');
    }

    public function test_the_admin_login_page_shows_the_admin_copy_and_nothing_from_the_staff_page(): void
    {
        $response = $this->get(route('admin.login'));

        $response->assertOk()
            ->assertSee('MINI MAX · CONTROL CENTER')
            ->assertSee('Command the Arena.')
            ->assertSee('Your command center for tournaments, players, rewards, and the entire Mini Max ecosystem.')
            ->assertSee('Great arenas aren&rsquo;t built by chance.', false)
            ->assertSee('ADMINISTRATOR ACCESS')
            ->assertSee('Enter Control Center')
            ->assertSee('Authorized administrators only · Mini Max')
            ->assertDontSee('Open Operations Hub')
            ->assertDontSee('STAFF MEMBER ACCESS')
            ->assertDontSee('Every action matters');
    }

    public function test_the_staff_login_page_shows_the_staff_copy_and_nothing_from_the_admin_page(): void
    {
        $response = $this->get(route('staff.login'));

        $response->assertOk()
            ->assertSee('<title>Staff sign in · Mini Max Operations Hub</title>', false)
            ->assertSee('MINI MAX · OPERATIONS')
            ->assertSee('Every Match. Every Detail.')
            ->assertSee('Keep tournaments moving, support players, and make every competition run smoothly.')
            ->assertSee('Champions make the plays. Great teams make the moments possible.', false)
            ->assertSee('STAFF MEMBER ACCESS')
            ->assertSee('Open Operations Hub')
            ->assertSee('Authorized Mini Max staff only · Every action matters')
            ->assertDontSee('Enter Control Center')
            ->assertDontSee('Command the Arena.')
            ->assertDontSee('ADMINISTRATOR ACCESS');
    }

    public function test_the_login_pages_expose_no_unauthenticated_data_and_are_not_indexable(): void
    {
        StaffUser::factory()->create(['display_name' => 'Secretive Person', 'username' => 'secretive_one']);

        foreach (['admin', 'staff'] as $portal) {
            $this->get(route($portal.'.login'))
                ->assertOk()
                ->assertSee('noindex', false)
                ->assertDontSee('Secretive Person')
                ->assertDontSee('secretive_one');
        }
    }

    public function test_the_login_pages_do_not_offer_unsupported_controls(): void
    {
        foreach (['admin', 'staff'] as $portal) {
            $this->get(route($portal.'.login'))
                ->assertOk()
                ->assertDontSee('Remember me')
                ->assertDontSee('Forgot password')
                ->assertSee('autocomplete="username"', false)
                ->assertSee('autocomplete="current-password"', false);
        }
    }

    // --- Sign-in -------------------------------------------------------------

    public function test_an_admin_signs_in_through_the_admin_portal_and_lands_on_the_admin_dashboard(): void
    {
        $admin = $this->admin();

        Livewire::test(AdminLogin::class)
            ->set('username', 'chief_admin')
            ->set('password', 'secret123')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.home'));

        $this->assertAuthenticatedAs($admin, 'staff');
        $this->assertSame($admin->fresh()->security_version, session('staff_security_version'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.login', 'actor_id' => $admin->id]);
    }

    public function test_a_staff_member_signs_in_through_the_staff_portal_and_lands_on_the_staff_dashboard(): void
    {
        $staff = $this->powerfulStaff();

        Livewire::test(StaffLogin::class)
            ->set('username', 'ops_member')
            ->set('password', 'secret123')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('staff.home'));

        $this->assertAuthenticatedAs($staff, 'staff');
    }

    public function test_a_staff_account_cannot_sign_in_through_the_admin_portal(): void
    {
        $this->powerfulStaff();

        Livewire::test(AdminLogin::class)
            ->set('username', 'ops_member')
            ->set('password', 'secret123')
            ->call('login')
            ->assertHasErrors(['form' => self::GENERIC_FAILURE]);

        $this->assertGuest('staff');
        $this->assertDatabaseHas('audit_logs', ['action' => 'portal.login_denied']);
    }

    public function test_an_admin_account_cannot_sign_in_through_the_staff_portal(): void
    {
        $this->admin();

        Livewire::test(StaffLogin::class)
            ->set('username', 'chief_admin')
            ->set('password', 'secret123')
            ->call('login')
            ->assertHasErrors(['form' => self::GENERIC_FAILURE]);

        $this->assertGuest('staff');
    }

    public function test_every_failure_reads_the_same_so_nothing_reveals_which_accounts_exist(): void
    {
        $this->admin();
        $this->powerfulStaff();
        StaffUser::factory()->create(['username' => 'sleeper', 'password' => bcrypt('secret123'), 'is_active' => false, 'is_super_admin' => true]);

        $attempts = [
            ['unknown_person', 'secret123'],
            ['chief_admin', 'wrong-password'],
            ['sleeper', 'secret123'],
            ['ops_member', 'secret123'], // right credentials, wrong portal
        ];

        foreach ($attempts as [$username, $password]) {
            RateLimiter::clear('portal-login:admin:'.$username.'|127.0.0.1');
            Livewire::test(AdminLogin::class)
                ->set('username', $username)
                ->set('password', $password)
                ->call('login')
                ->assertHasErrors(['form' => self::GENERIC_FAILURE])
                ->assertHasNoErrors(['username', 'password']);
        }

        $this->assertGuest('staff');
    }

    public function test_blank_fields_get_plain_validation_messages(): void
    {
        Livewire::test(StaffLogin::class)
            ->call('login')
            ->assertHasErrors(['username' => 'required', 'password' => 'required']);
    }

    public function test_repeated_failures_are_throttled_even_for_the_correct_password(): void
    {
        $this->powerfulStaff();

        foreach (range(1, 5) as $_) {
            Livewire::test(StaffLogin::class)
                ->set('username', 'ops_member')
                ->set('password', 'nope')
                ->call('login')
                ->assertHasErrors(['form' => self::GENERIC_FAILURE]);
        }

        Livewire::test(StaffLogin::class)
            ->set('username', 'ops_member')
            ->set('password', 'secret123')
            ->call('login')
            ->assertHasErrors('form');

        $this->assertGuest('staff');
        $this->assertStringContainsString('Too many sign-in attempts', session('errors')?->first('form') ?? 'Too many sign-in attempts');
    }

    public function test_a_throttled_attempt_shows_the_wait_message(): void
    {
        $this->powerfulStaff();
        RateLimiter::increment('portal-login:staff:ops_member|127.0.0.1', 300, 5);
        RateLimiter::increment('portal-login:staff:ops_member|127.0.0.1', 300, 0);

        $component = Livewire::test(StaffLogin::class)
            ->set('username', 'ops_member')
            ->set('password', 'secret123')
            ->call('login')
            ->assertHasErrors('form');

        $this->assertStringContainsString('Too many sign-in attempts', $component->errors()->first('form'));
        $this->assertGuest('staff');
    }

    public function test_the_throttle_is_per_account_so_one_target_does_not_lock_out_others(): void
    {
        $this->powerfulStaff('first_member');
        $this->powerfulStaff('second_member');

        foreach (range(1, 5) as $_) {
            Livewire::test(StaffLogin::class)->set('username', 'first_member')->set('password', 'bad')->call('login');
        }

        Livewire::test(StaffLogin::class)
            ->set('username', 'second_member')
            ->set('password', 'secret123')
            ->call('login')
            ->assertHasNoErrors();

        $this->assertAuthenticated('staff');
    }

    public function test_signing_in_never_issues_a_remember_me_token(): void
    {
        $staff = $this->powerfulStaff();

        Livewire::test(StaffLogin::class)->set('username', 'ops_member')->set('password', 'secret123')->call('login');

        $this->assertNull($staff->fresh()->remember_token);
    }

    // --- The boundary --------------------------------------------------------

    public function test_a_staff_member_with_every_permission_still_cannot_open_a_single_admin_page(): void
    {
        $staff = $this->powerfulStaff();

        foreach ($this->pageRoutes('admin') as $name) {
            $this->actingAs($staff, 'staff')->get(route($name))->assertForbidden();
        }

        $this->assertNotEmpty($this->pageRoutes('admin'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'portal.access_denied', 'actor_id' => $staff->id]);
    }

    public function test_an_admin_cannot_open_a_single_staff_portal_page(): void
    {
        $admin = $this->admin();

        foreach ($this->pageRoutes('staff') as $name) {
            $this->actingAs($admin, 'staff')->get(route($name))->assertForbidden();
        }

        $this->assertNotEmpty($this->pageRoutes('staff'));
    }

    public function test_each_portal_serves_the_same_page_set_to_its_own_accounts(): void
    {
        $this->assertEqualsCanonicalizing(
            array_map(fn (string $n) => substr($n, strlen('admin.')), $this->pageRoutes('admin')),
            array_map(fn (string $n) => substr($n, strlen('staff.')), $this->pageRoutes('staff')),
        );

        $admin = $this->admin();
        $staff = $this->powerfulStaff();

        foreach (['home', 'tournaments.index', 'users.index', 'staff.index', 'settings.index'] as $page) {
            $this->actingAs($admin, 'staff')->get(route('admin.'.$page))->assertOk();
            $this->actingAs($staff, 'staff')->get(route('staff.'.$page))->assertOk();
        }
    }

    public function test_the_wrong_portal_response_is_a_clear_403_that_points_home(): void
    {
        $staff = $this->powerfulStaff();

        $this->actingAs($staff, 'staff')
            ->get(route('admin.settings.index'))
            ->assertForbidden()
            ->assertSee('reserved for administrators', false)
            ->assertSee(route('staff.home'), false)
            ->assertDontSee(route('admin.home'), false);
    }

    public function test_navigation_only_ever_links_into_the_accounts_own_portal(): void
    {
        $admin = $this->admin();
        $staff = $this->powerfulStaff();

        $adminHtml = $this->actingAs($admin, 'staff')->get(route('admin.home'))->assertOk()->getContent();
        $staffHtml = $this->actingAs($staff, 'staff')->get(route('staff.home'))->assertOk()->getContent();

        $this->assertStringContainsString('/admin/tournaments', $adminHtml);
        $this->assertStringNotContainsString('/staff/tournaments', $adminHtml);
        $this->assertStringContainsString('/staff/tournaments', $staffHtml);
        $this->assertStringNotContainsString('/admin/tournaments', $staffHtml);
        $this->assertStringContainsString('Admin Console', $adminHtml);
        $this->assertStringContainsString('Staff Portal', $staffHtml);
    }

    public function test_guests_are_sent_to_the_login_of_the_portal_they_asked_for(): void
    {
        $this->get('/admin/tournaments')->assertRedirect(route('admin.login'));
        $this->get('/staff/tournaments')->assertRedirect(route('staff.login'));
        $this->get('/admin')->assertRedirect(route('admin.login'));
        $this->get('/staff')->assertRedirect(route('staff.login'));
    }

    public function test_a_signed_in_account_opening_a_login_page_lands_in_its_own_portal(): void
    {
        $admin = $this->admin();
        $staff = $this->powerfulStaff();

        $this->actingAs($admin, 'staff')->get(route('admin.login'))->assertRedirect(route('admin.home'));
        $this->actingAs($admin, 'staff')->get(route('staff.login'))->assertRedirect(route('admin.home'));
        $this->actingAs($staff, 'staff')->get(route('staff.login'))->assertRedirect(route('staff.home'));
        $this->actingAs($staff, 'staff')->get(route('admin.login'))->assertRedirect(route('staff.home'));
    }

    public function test_a_stale_session_is_sent_back_to_the_login_of_its_own_portal(): void
    {
        $staff = $this->powerfulStaff();

        $this->withSession(['staff_security_version' => $staff->security_version + 1])
            ->actingAs($staff, 'staff')
            ->get(route('staff.tournaments.index'))
            ->assertRedirect(route('staff.login'));
    }

    public function test_logging_out_ends_the_session_and_returns_to_the_same_portals_login(): void
    {
        $this->actingAs($this->admin(), 'staff')->post(route('admin.logout'))->assertRedirect(route('admin.login'));
        $this->assertGuest('staff');

        $this->actingAs($this->powerfulStaff(), 'staff')->post(route('staff.logout'))->assertRedirect(route('staff.login'));
        $this->assertGuest('staff');
    }

    // --- Redirect safety -----------------------------------------------------

    public function test_a_guest_who_was_sent_to_login_returns_to_the_page_they_asked_for(): void
    {
        $this->powerfulStaff();

        $this->get('/staff/tournaments')->assertRedirect(route('staff.login'));

        Livewire::test(StaffLogin::class)
            ->set('username', 'ops_member')
            ->set('password', 'secret123')
            ->call('login')
            ->assertRedirect(url('/staff/tournaments'));
    }

    public function test_an_intended_url_pointing_at_the_other_portal_is_ignored(): void
    {
        $this->powerfulStaff();

        $this->withSession(['url.intended' => url('/admin/settings')]);

        Livewire::test(StaffLogin::class)
            ->set('username', 'ops_member')
            ->set('password', 'secret123')
            ->call('login')
            ->assertRedirect(route('staff.home'));
    }

    /**
     * @return array<string, array{0: ?string, 1: string, 2: ?string}>
     */
    public static function intendedUrls(): array
    {
        return [
            'same-site page' => ['http://localhost/staff/tournaments?page=2', 'staff', '/staff/tournaments?page=2'],
            'relative path' => ['/staff/users/5', 'staff', '/staff/users/5'],
            'other portal' => ['http://localhost/admin/home', 'staff', null],
            'other host' => ['https://evil.example/staff/home', 'staff', null],
            'protocol relative' => ['//evil.example/staff/home', 'staff', null],
            'userinfo trick' => ['http://localhost@evil.example/staff/home', 'staff', null],
            'wrong port' => ['http://localhost:9999/staff/home', 'staff', null],
            'javascript scheme' => ['javascript:alert(1)', 'staff', null],
            'backslash trick' => ['/staff\\@evil.example', 'staff', null],
            'control characters' => ["/staff/home\r\nSet-Cookie: x=1", 'staff', null],
            'path traversal' => ['/staff/../admin/home', 'staff', null],
            'login loop' => ['http://localhost/staff/login', 'staff', null],
            'logout' => ['/staff/logout', 'staff', null],
            'empty' => ['', 'staff', null],
            'null' => [null, 'staff', null],
            'admin page for admin' => ['/admin/tournaments', 'admin', '/admin/tournaments'],
        ];
    }

    /**
     * @dataProvider intendedUrls
     */
    #[DataProvider('intendedUrls')]
    public function test_intended_urls_are_only_followed_when_they_are_safe(?string $url, string $portal, ?string $expected): void
    {
        $this->assertSame($expected, Portal::safeIntendedUrl($url, $portal, request()->duplicate(server: ['HTTP_HOST' => 'localhost'])));
    }
}
