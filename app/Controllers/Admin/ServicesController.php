<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AdminServices;
use App\Services\AdminSettings;
use App\Services\Assets;
use App\Services\Audit;
use App\Services\Catalog;

final class ServicesController extends BaseController
{
    public function meta(Request $req, array $p): Response
    {
        return $this->ok(['meta' => AdminServices::meta()]);
    }

    public function list(Request $req, array $p): Response
    {
        return $this->ok(['services' => AdminServices::list(), 'meta' => AdminServices::meta()]);
    }

    public function show(Request $req, array $p): Response
    {
        return $this->ok(['service' => AdminServices::get($this->id($p))]);
    }

    public function create(Request $req, array $p): Response
    {
        $in = $req->json();
        $admin = $this->admin($req);
        return $this->once($req, 'service-save', static fn(): array => ['service' => AdminServices::save(null, $in, $req, $admin)]);
    }

    public function update(Request $req, array $p): Response
    {
        $id = $this->id($p);
        $in = $req->json();
        $admin = $this->admin($req);
        return $this->once($req, 'service-save', static fn(): array => ['service' => AdminServices::save($id, $in, $req, $admin)]);
    }

    public function delete(Request $req, array $p): Response
    {
        AdminServices::delete($this->id($p), $req, $this->admin($req));
        return $this->ok(['message' => 'Service deleted.']);
    }

    /** POST /admin/api/services/icon — multipart upload; returns the stored path for the service form to save. */
    public function icon(Request $req, array $p): Response
    {
        $file = $req->files['file'] ?? null;
        if (!is_array($file)) {
            throw new HttpException(422, 'Choose an image to upload.', 'upload_failed');
        }
        $img = Assets::fromUpload($file, 'service_icon');
        Audit::log('upload.image', 'service_icon', null, ['bytes' => $img['bytes']], $req, $this->admin($req));
        return $this->ok(['image' => $img]);
    }

    /** PATCH /admin/api/platforms/{id} */
    public function platform(Request $req, array $p): Response
    {
        return $this->ok(['platform' => AdminSettings::savePlatform($this->id($p), $req->json(), $req, $this->admin($req)), 'platforms' => Catalog::platforms(false)]);
    }

    /** POST /admin/api/platforms/{id}/logo (multipart) */
    public function platformLogo(Request $req, array $p): Response
    {
        $file = $req->files['file'] ?? null;
        if (!is_array($file)) {
            throw new HttpException(422, 'Choose an image to upload.', 'upload_failed');
        }
        return $this->ok(['platform' => AdminSettings::uploadPlatformLogo($this->id($p), $file, $req, $this->admin($req))]);
    }

    public function platformLogoReset(Request $req, array $p): Response
    {
        return $this->ok(['platform' => AdminSettings::resetPlatformLogo($this->id($p), $req, $this->admin($req))]);
    }
}
