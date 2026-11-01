-- Run after 1.15.2.sql. Adds the mind map media type. Safe to repeat.
ALTER TABLE lessons MODIFY media_type ENUM('text','youtube','video','pdf','file','image','link','audio','quiz','assignment','slides','mindmap') NOT NULL DEFAULT 'text';
ALTER TABLE lesson_media MODIFY media_type ENUM('text','youtube','video','pdf','file','image','link','audio','quiz','assignment','slides','mindmap') NOT NULL DEFAULT 'text';
