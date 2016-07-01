'use strict';

require('es6-promise').polyfill();

var gulp	= require('gulp');
var newer	= require('gulp-newer');
var concat	= require('gulp-concat');
var notify	= require('gulp-notify');

// Ignore any file prefixed with an underscore //
var less_files = ['src/**/*.less','!src/**/_*.less'];

// Ignore any min files, and the output file //
var css_output = 'all.css';
var css_min_output = 'all.min.css';
var css_files = ['output/**/*.css','!output/**/_*.css','output/**/*.min.css','!output/'+css_output];

var js_output = 'all.js';
var js_min_output = 'all.min.js';
var js_files = ['src/**/*.js','!src/**/_*.js','output/**/*.min.js','!output/'+js_output];

/* Process the individual LESS files */
gulp.task('less', function() {
	var sourcemaps	= require('gulp-sourcemaps');
	var less		= require('gulp-less-sourcemap');
	var autoprefix	= require('less-plugin-autoprefix');
	// NOTE: We're running autoprefixer as a less plugin, due to a bug in postcss sourcemaps
		
	return gulp.src( less_files )
		.pipe( newer({dest:"output",ext:".css"}) )
		.pipe( sourcemaps.init() )
			.pipe( less({
				plugins:[
					new autoprefix(/*{
						browsers: ["last 2 versions"]
					}*/)
				]
			}) )
		.pipe( sourcemaps.write() )
		.pipe( gulp.dest("output/") );
});

/* Next, combine the output CSS files */
gulp.task('css', ['less'], function() {
	// NOTE: PostCSS needs to be run here, due to a bug with sourcemaps
	var postcss = require('gulp-postcss');

	return gulp.src( css_files )
		.pipe( newer( "output/"+css_output) )
		.pipe( postcss([ 
//			require('autoprefixer')
		]) )
		.pipe( concat( css_output ) )
		.pipe( gulp.dest( "output/" ) );	
});

/* Finally, minifiy the CSS files */
gulp.task('css-min', ['css'], function() {
	// Benchmarks: http://goalsmashers.github.io/css-minification-benchmark/
	var cleancss	= require('gulp-cleancss');		// Faster, similar results
//	var cssnano		= require('gulp-cssnano');

	return gulp.src( "output/"+css_output )
		.pipe( newer( "output/"+css_min_output ) )
		.pipe( cleancss() )
//		.pipe( cssnano() )
		.pipe( concat( css_min_output ) )
		.pipe( gulp.dest( "output/" ) );	
});


/* Merge all JS files */
gulp.task('js', function() {
	return gulp.src( js_files )
		.pipe( newer( "output/"+js_output ) )
		.pipe( concat( js_output ) )
		.pipe( gulp.dest( "output/" ) );
});

/* Minifiy merged file */
gulp.task('js-min', ['js'], function() {
	var uglify = require('gulp-uglify');
	
	return gulp.src( "output/"+js_output )
		.pipe( newer( "output/"+js_min_output ) )
		.pipe( uglify() )
		.pipe( concat( js_min_output ) )
		.pipe( gulp.dest( "output/" ) );
});

/* Nuke the output folder */
gulp.task('clean', function() {
	var del = require('del');
	
	return del( 'output/**/*' );
});

// TODO: Popup notifications when a watch catches an error/linting error
//		.pipe( notify("hello") )


// NOTE: Use gulp-watch instead: https://www.npmjs.com/package/gulp-watch
//gulp.task('less-watch', ['css','js'] function () {
//	gulp.watch(less_files, ['css'])
//	gulp.watch(js_files, ['js'])
//});

gulp.task('default', ['css-min','js-min'], function() {
});
