<?php

namespace Ibinet\Helpers;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;

class FormHelper{

    /**
     * Handle upload file event with s3
     *
     * @param File $image
     * @param String $directory
     * @param Boolean $isWatermark
     * @return String $filename
     */
    public static function uploadImage(
        $image,
        $directory,
        $width = 500,
        $height = null,
        $isWatermark = false,
        $expenseReport = null,
        $mapsObj = null,
        $request = null
    ){
        $app = env('AWS_APP_CODE');
        $fileName = md5(date('YmdHis')."-{$directory}").'.'.$image->getClientOriginalExtension();

        $imageManager = ImageManager::gd();

        $imageManager = $imageManager->read($image->getRealPath());
        $imageManager->resize($width, $height, function ($constraint) {
            $constraint->aspectRatio();
        });

        if ($isWatermark) {
            $imageManager = self::addWatermarkImage($imageManager, $expenseReport, $mapsObj, $request);
        } else{
            $imageManager = $imageManager->toPng()->toFilePointer();
        }

        Storage::disk('s3')->put("{$app}/{$directory}/{$fileName}", $imageManager, 'public');
        return "/{$app}/{$directory}/{$fileName}";
    }

    /**
     * Add watermark on image
     *
     * @param Image $imageManager
     * @return Image
     */
    public static function addWatermarkImage($imageManager, $expenseReport, $mapsObj, $request)
    {
        $imageManager->text(date('Y-m-d H:i:s', strtotime($request->created_at)), $imageManager->width() - 20, $imageManager->height() - 130, function($font) {
            $font->file(public_path('fonts/Roboto/Roboto-Regular.ttf'));
            $font->size(18);
            $font->color('#00ff01');
            $font->align('right');
            $font->valign('bottom');
        });

        $imageManager->text($request->upload_lat_long, $imageManager->width() - 20, $imageManager->height() - 100, function($font) {
            $font->file(public_path('fonts/Roboto/Roboto-Regular.ttf'));
            $font->size(18);
            $font->color('#00ff01');
            $font->align('right');
            $font->valign('bottom');
        });

        $imageManager->text($mapsObj->plus_code->compound_code ?? '-', $imageManager->width() - 20, $imageManager->height() - 70, function($font) {
            $font->file(public_path('fonts/Roboto/Roboto-Regular.ttf'));
            $font->size(18);
            $font->color('#00ff01');
            $font->align('right');
            $font->valign('bottom');
        });

        $imageManager->text($expenseReport->assignmentTo->name, $imageManager->width() - 20, $imageManager->height() - 40, function($font) {
            $font->file(public_path('fonts/Roboto/Roboto-Regular.ttf'));
            $font->size(18);
            $font->color('#00ff01');
            $font->align('right');
            $font->valign('bottom');
        });

        $imageManager->text($request->code, $imageManager->width() - 20, $imageManager->height() - 10, function($font) {
            $font->file(public_path('fonts/Roboto/Roboto-Regular.ttf'));
            $font->size(18);
            $font->color('#00ff01');
            $font->align('right');
            $font->valign('bottom');
        });

        return $imageManager->toPng()->toFilePointer();
    }

}
