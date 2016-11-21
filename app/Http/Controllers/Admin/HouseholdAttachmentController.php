<?php

namespace App\Http\Controllers\Admin;

use App\Base\Controllers\AdminController;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Http\Controllers\Controller;

use App\HouseholdAttachment;

use Storage;
use Auth;

class HouseholdAttachmentController extends AdminController
{
    public function show($id) {
        $file = HouseholdAttachment::find($id);
        if(!$file) {
            abort(404);
        }
        if(!Auth::user()->hasRole('admin') && $file->owner_user_id != Auth::user()){
            abort(403);
        }
        $pathinfo = pathinfo($file->path);
        switch(strtolower($pathinfo['extension'])){
        case 'pdf': $type = 'application/pdf'; break;
        default: $type = 'application/octet-stream';
        }
        return response(Storage::disk('forms')->get($file->path))
            ->header('Content-type', $type)
            ->header('Content-Disposition', 'attachment; filename="' . explode("_", $pathinfo['basename'], 2)[1] . '"');
    }
}
