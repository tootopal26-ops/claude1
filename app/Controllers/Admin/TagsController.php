<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Tags;

/**
 * Admin → Tags & Badges. Every route sits behind Middleware::adminApi (signed-in admin, same-origin check, CSRF token on every unsafe method);
 * validation, audit logging and the "predefined tags cannot be deleted" rule live in App\Services\Tags.
 */
final class TagsController extends BaseController
{
    /** Until migration 003 has been applied there is no service_tags table: say so plainly instead of a generic "unavailable". */
    private function ready(): void
    {
        if (!Tags::available()) {
            throw new HttpException(409, 'Tags & Badges needs a database update first. Open System and choose “Apply database updates”.', 'migration_pending');
        }
    }

    public function list(Request $req, array $p): Response
    {
        $this->ready();
        return $this->ok(['tags' => Tags::adminList(), 'meta' => Tags::meta()]);
    }

    public function create(Request $req, array $p): Response
    {
        $this->ready();
        $in = $req->json();
        $admin = $this->admin($req);
        return $this->once($req, 'tag-save', static fn(): array => ['tag' => Tags::save(null, $in, $req, $admin)]);
    }

    public function update(Request $req, array $p): Response
    {
        $this->ready();
        $id = $this->id($p);
        $in = $req->json();
        $admin = $this->admin($req);
        return $this->once($req, 'tag-save', static fn(): array => ['tag' => Tags::save($id, $in, $req, $admin)]);
    }

    public function delete(Request $req, array $p): Response
    {
        $this->ready();
        $r = Tags::delete($this->id($p), $req, $this->admin($req));
        return $this->ok(['message' => 'Tag deleted.', 'unassigned' => $r['unassigned']]);
    }
}
