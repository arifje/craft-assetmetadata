<?php

namespace carlcs\assetmetadata;

use craft\base\Model;
use craft\elements\Asset;

/**
 * Plugin settings. Override them in `config/asset-metadata.php`.
 */
class Settings extends Model
{
    /**
     * @var array Config settings for the getID3 library, applied as public properties of the `getID3` instance.
     * @see https://github.com/JamesHeinrich/getID3/blob/master/getid3/getid3.php
     */
    public array $getId3 = [
        'option_extra_info' => true,
        'option_tags_html' => false,
        'option_save_attachments' => false,
    ];

    /**
     * @var int|false Size in bytes of the file chunk that gets downloaded from files on remote
     * (non-local) filesystems before they are analyzed. Set to `false` to always download complete files.
     */
    public int|false $downloadChunkSize = 256 * 1024;

    /**
     * @var array|bool The file kinds for which the complete file size is faked when only a chunk of the
     * file was downloaded from a remote filesystem. This is required to reliably extract certain metadata,
     * like the playtime of an audio file. Set to `true` to fake the size for all file kinds.
     */
    public array|bool $fakeCompleteFileSize = [
        Asset::KIND_AUDIO,
    ];

    /**
     * @var int|null Maximum length (in characters) of a rendered subfield value. Longer values are truncated
     * before they are stored so oversized metadata can’t break saving the asset. Set to `null` to disable.
     * @since 5.2.0
     */
    public ?int $maxValueLength = 65535;
}
