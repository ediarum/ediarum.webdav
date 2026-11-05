<?php

namespace Tests\Unit\App\Helpers;

use App\Helpers\FolderContents;
use PHPUnit\Framework\TestCase;

class ExistFolderContentsTest extends TestCase
{
    public function test_existdb_contents_are_parsed(): void
    {
        $filePath = __DIR__ . '/../../../resources/example_folder_contents.xml';
        $xml = file_get_contents($filePath);

        $folderContents = new FolderContents();
        $folderContents->parseExistDBCollectionContents($xml);

        $this->assertEquals(5, $folderContents->getFolders()->count());
        $this->assertEquals(1, $folderContents->getFiles()->count());

        $this->assertEquals('An', $folderContents->getFolders()[0]);
        $this->assertEquals('Blemmydes-Aposemeioseis-Bon-3637.xml', $folderContents->getFiles()->first());

    }

    public function test_file_system_contents_are_parsed():void
    {
        $filePath = __DIR__ . '/../../../resources/cagb_data';
        $folderContents = new FolderContents();
        $folderContents->parseFileSystemFolderContents($filePath);

        $this->assertEquals("example.xml", $folderContents->getFiles()->first());
        $this->assertEquals(2, $folderContents->getFolders()->count());

    }

    public function test_empty_directory_yields_no_files_or_folders(): void
    {
        $path = sys_get_temp_dir() . '/folder-contents-empty-' . uniqid();
        mkdir($path);

        try {
            $folderContents = new FolderContents();
            $folderContents->parseFileSystemFolderContents($path);

            $this->assertEquals(0, $folderContents->getFiles()->count());
            $this->assertEquals(0, $folderContents->getFolders()->count());
        } finally {
            rmdir($path);
        }
    }

    public function test_dotfiles_other_than_dot_and_dotdot_are_included(): void
    {
        // parseFileSystemFolderContents() only special-cases literal '.' and '..' -- any other
        // dotfile (e.g. .gitkeep) is treated as a normal file, which is easy to get wrong if this
        // is ever "cleaned up" to skip all hidden files.
        $path = sys_get_temp_dir() . '/folder-contents-dotfiles-' . uniqid();
        mkdir($path);
        touch($path . '/.hidden');

        try {
            $folderContents = new FolderContents();
            $folderContents->parseFileSystemFolderContents($path);

            $this->assertEquals(1, $folderContents->getFiles()->count());
            $this->assertEquals('.hidden', $folderContents->getFiles()->first());
        } finally {
            unlink($path . '/.hidden');
            rmdir($path);
        }
    }

    public function test_unrecognized_element_tag_throws(): void
    {
        $xml = <<<XML
<exist:result xmlns:exist="http://exist.sourceforge.net/NS/exist">
    <exist:collection name="/db/data" created="2025-01-06T15:22:54.382Z" owner="admin" group="g" permissions="rwx">
        <exist:something-else name="mystery"/>
    </exist:collection>
</exist:result>
XML;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unrecognized element tag name');

        (new FolderContents())->parseExistDBCollectionContents($xml);
    }
}
