(function($){ // Open closure

// Local vars
var Scroller, ajaxurl, stats, type, text, totop, timer;

// IE requires special handling
var isIE = ( -1 != navigator.userAgent.search( 'MSIE' ) );
if ( isIE ) {
	var IEVersion = navigator.userAgent.match(/MSIE\s?(\d+)\.?\d*;/);
	var IEVersion = parseInt( IEVersion[1] );
}

/**
 * Loads new posts when users scroll near the bottom of the page.
 */
Scroller = function( settings ) {
	var self = this;

	// Initialize our variables
	this.id               = settings.id;
	this.body             = $( document.body );
	this.window           = $( window );
	this.element          = $( '#' + settings.id );
	this.wrapperClass     = settings.wrapper_class;
	this.ready            = true;
	this.disabled         = false;
	this.page             = 1;
	this.offset           = settings.offset;
	this.order            = settings.order;
	this.throttle         = false;
	this.handle           = '<div id="infinite-handle"><span>' + text.replace( '\\', '' ) + '</span></div>';
	this.google_analytics = settings.google_analytics;
	this.history          = settings.history;
	this.origURL          = window.location.href;

	// Footer settings
	this.footer           = $( '#infinite-footer' );
	this.footer.wrap      = settings.footer;

	// We have two type of infinite scroll
	// cases 'scroll' and 'click'

	if ( type == 'scroll' ) {
		// Bind refresh to the scroll event
		// Throttle to check for such case every 300ms

		// On event the case becomes a fact
		this.window.bind( 'scroll.infinity', function() {
			this.throttle = true;
		});

		// Go back top method
		self.gotop();

		setInterval( function() {
			if ( this.throttle ) {
				// Once the case is the case, the action occurs and the fact is no more
				this.throttle = false;
				// Reveal or hide footer
				self.thefooter();
				// Fire the refresh
				self.refresh();
			}
		}, 300 );

		// Ensure that enough posts are loaded to fill the initial viewport, to compensate for short posts and large displays.
		self.ensureFilledViewport();
		this.body.bind( 'post-load', { self: self }, self.checkViewportOnLoad );
	} else if ( type == 'click' ) {
		this.element.append( self.handle );
		this.element.delegate( '#infinite-handle', 'click.infinity', function() {
			// Handle the handle
			$( '#infinite-handle' ).remove();
			// Fire the refresh
			self.refresh();
		});
	}
};

/**
 * Check whether we should fetch any additional posts.
 *
 * By default, checks whether the bottom of the viewport is within one
 * viewport-height of the bottom of the content.
 */
Scroller.prototype.check = function() {
	var bottom = this.window.scrollTop() + this.window.height(),
		threshold = this.element.offset().top + this.element.outerHeight() - this.window.height();

	return bottom > threshold;
};

/**
 * Renders the results from a successful response.
 */
Scroller.prototype.render = function( response ) {
	this.body.addClass( 'infinity-success' );

	// Check if we can wrap the html
	this.element.append( response.html );

	this.body.trigger( 'post-load' );
	this.ready = true;
};

/**
 * Returns the object used to query for new posts.
 */
Scroller.prototype.query = function() {
	return {
		page:  this.page,
		order: this.order,
		scripts: window.infiniteScroll.settings.scripts,
		styles: window.infiniteScroll.settings.styles
	};
};

/**
 * Scroll back to top.
 */
Scroller.prototype.gotop = function() {
	var blog = $( '#infinity-blog-title' );

	blog.attr( 'title', totop );

	// Scroll to top on blog title
	blog.bind( 'click', function( e ) {
		$( 'html, body' ).animate( { scrollTop: 0 }, 'fast' );
		e.preventDefault();
	});
};


/**
 * The infinite footer.
 */
Scroller.prototype.thefooter = function() {
	var self  = this,
		width;

	// Check if we have an id for the page wrapper
	if ( $.type( this.footer.wrap ) === "string" ) {
		width = $( 'body #' + this.footer.wrap ).outerWidth();

		// Make the footer match the width of the page
		if ( width > 479 )
			this.footer.find( '.container' ).css( 'width', width );
	}

	// Reveal footer
	if ( this.window.scrollTop() >= 350 )
		self.footer.animate( { 'bottom': 0 }, 'fast' );
	else if ( this.window.scrollTop() < 350 )
		self.footer.animate( { 'bottom': '-50px' }, 'fast' );
};


/**
 * Controls the flow of the refresh. Don't mess.
 */
Scroller.prototype.refresh = function() {
	var	self   = this,
		query, jqxhr, load, loader, color;

	// If we're disabled, ready, or don't pass the check, bail.
	if ( this.disabled || ! this.ready || ! this.check() )
		return;

	// Let's get going -- set ready to false to prevent
	// multiple refreshes from occurring at once.
	this.ready = false;

	// Create a loader element to show it's working.
	loader = '<span class="infinite-loader"></span>';
	this.element.append( loader );

	loader = this.element.find( '.infinite-loader' );
	color = loader.css( 'color' );
	try {
		loader.spin( 'medium-left', color );
	} catch ( error ) { }

	// Generate our query vars.
	query = $.extend({
		action: 'infinite_scroll'
	}, this.query() );

	// Fire the ajax request.
	jqxhr = $.get( infiniteScroll.settings.ajaxurl, query );

	// Allow refreshes to occur again if an error is triggered.
	jqxhr.fail( function() {
		loader.hide();
		self.ready = true;
	});

	// Success handler
	jqxhr.done( function( response ) {

			// On success, let's hide the loader circle.
			loader.hide();

			// Check for and parse our response.
			if ( ! response )
				return;

			response = $.parseJSON( response );

			if ( ! response || ! response.type )
				return;

			// If there are no remaining posts...
			if ( response.type == 'empty' ) {
				// Disable the scroller.
				self.disabled = true;
				// Update body classes, allowing the footer to return to static positioning
				self.body.addClass( 'infinity-end' ).removeClass( 'infinity-success' );

			// If we've succeeded...
			} else if ( response.type == 'success' ) {
				// If additional scripts are required by the incoming set of posts, parse them
				if ( response.scripts ) {
					$( response.scripts ).each( function() {
						// Add script handle to list of those already parsed
						window.infiniteScroll.settings.scripts.push( this.handle );

						// Output extra data, if present
						if ( this.extra_data ) {
							var data = document.createElement('script'),
								dataContent = document.createTextNode( "//<![CDATA[ \n" + this.extra_data + "\n//]]>" );

							data.type = 'text/javascript';
							data.appendChild( dataContent );

							document.getElementsByTagName( this.footer ? 'body' : 'head' )[0].appendChild(data);
						}

						// Build script tag and append to DOM in requested location
						var script = document.createElement('script');
						script.type = 'text/javascript';
						script.src = this.src;
						script.id = this.handle;
						document.getElementsByTagName( this.footer ? 'body' : 'head' )[0].appendChild(script);
					} );
				}

				// If additional stylesheets are required by the incoming set of posts, parse them
				if ( response.styles ) {
					$( response.styles ).each( function() {
						// Add stylesheet handle to list of those already parsed
						window.infiniteScroll.settings.styles.push( this.handle );

						// Build link tag
						var style = document.createElement('link');
						style.rel = 'stylesheet';
						style.href = this.src;
						style.id = this.handle + '-css';

						// Destroy link tag if a conditional statement is present and either the browser isn't IE, or the conditional doesn't evaluate true
						if ( this.conditional && ( ! isIE || ! eval( this.conditional.replace( /%ver/g, IEVersion ) ) ) )
							var style = false;

						// Append link tag if necessary
						if ( style )
							document.getElementsByTagName('head')[0].appendChild(style);
					} );
				}

				// Increment the page number
				self.page++;

				// Record pageview in WP Stats, if available.
				if ( stats )
					new Image().src = document.location.protocol + '//stats.wordpress.com/g.gif?' + stats + '&post=0&baba=' + Math.random();

				// Add new posts to the postflair object
				if ( 'object' == typeof response.postflair && 'object' == typeof WPCOM_sharing_counts )
					WPCOM_sharing_counts = $.extend( WPCOM_sharing_counts, response.postflair );

				// Render the results
				self.render.apply( self, arguments );

				// If 'click' type, add back the handle
				if ( type == 'click' )
					self.element.append( self.handle );

				// Fire Google Analytics pageview
				if ( self.google_analytics && 'object' == typeof _gaq )
					_gaq.push(['_trackPageview', self.history.path.replace( /%d/, self.page ) ]);
			}
		});

	return jqxhr;
};

/**
 * Trigger IS to load additional posts if the initial posts don't fill the window.
 * On large displays, or when posts are very short, the viewport may not be filled with posts, so we overcome this by loading additional posts when IS initializes.
 */
Scroller.prototype.ensureFilledViewport = function() {
	var	self = this,
	   	windowHeight = self.window.height(),
	   	postsHeight = self.element.height()
	   	aveSetHeight = 0,
	   	wrapperQty = 0;

	// Account for situations where postsHeight is 0 because child list elements are floated
	if ( postsHeight === 0 ) {
		$( self.element.selector + ' > li' ).each( function() {
			postsHeight += $( this ).height();
		} );

		if ( postsHeight === 0 ) {
			self.body.unbind( 'post-load', self.checkViewportOnLoad );
			return;
		}
	}

	// Calculate average height of a set of posts to prevent more posts than needed from being loaded.
	$( '.' + self.wrapperClass ).each( function() {
		aveSetHeight += $( this ).height();
		wrapperQty++;
	} );

	if ( wrapperQty > 0 )
		aveSetHeight = aveSetHeight / wrapperQty;
	else
		aveSetHeight = 0;

	// Load more posts if space permits, otherwise stop checking for a full viewport
	if ( postsHeight < windowHeight && ( postsHeight + aveSetHeight < windowHeight ) ) {
		self.ready = true;
		self.refresh();
	}
	else {
		self.body.unbind( 'post-load', self.checkViewportOnLoad );
	}
}

/**
 * Event handler for ensureFilledViewport(), tied to the post-load trigger.
 * Necessary to ensure that the variable `this` contains the scroller when used in ensureFilledViewport(). Since this function is tied to an event, `this` becomes the DOM element related the event is tied to.
 */
Scroller.prototype.checkViewportOnLoad = function( ev ) {
	ev.data.self.ensureFilledViewport();
}

/**
 * Identify archive page that corresponds to majority of posts shown in the current browser window.
 */
Scroller.prototype.determineURL = function () {
	var self         = window.infiniteScroll.scroller,
		windowTop    = $( window ).scrollTop(),
		windowBottom = windowTop + $( window ).height(),
		windowSize   = windowBottom - windowTop,
		setsInView   = [],
		pageNum      = false;

	// Find out which sets are in view
	$( '.' + self.wrapperClass ).each( function() {
		var id         = $( this ).attr( 'id' ),
			setTop     = $( this ).offset().top,
			setHeight  = $( this ).outerHeight(),
			setBottom  = 0,
			setPageNum = $( this ).data( 'page-num' );

		// Account for containers that have no height because their children are floated elements.
		if ( 0 == setHeight ) {
			$( '> *', this ).each( function() {
				setHeight += $( this ).outerHeight();
			} );
		}

		// Determine position of bottom of set by adding its height to the scroll position of its top.
		setBottom = setTop + setHeight;

		// Populate setsInView object. While this logic could all be combined into a single conditional statement, this is easier to understand.
		if ( setTop < windowTop && setBottom > windowBottom ) { // top of set is above window, bottom is below
			setsInView.push({'id': id, 'top': setTop, 'bottom': setBottom, 'pageNum': setPageNum });
		}
		else if( setTop > windowTop && setTop < windowBottom ) { // top of set is between top (gt) and bottom (lt)
			setsInView.push({'id': id, 'top': setTop, 'bottom': setBottom, 'pageNum': setPageNum });
		}
		else if( setBottom > windowTop && setBottom < windowBottom ) { // bottom of set is between top (gt) and bottom (lt)
			setsInView.push({'id': id, 'top': setTop, 'bottom': setBottom, 'pageNum': setPageNum });
		}
	} );

	// Parse number of sets found in view in an attempt to update the URL to match the set that comprises the majority of the window.
	if ( 0 == setsInView.length ) {
		pageNum = -1;
	}
	else if ( 1 == setsInView.length ) {
		var setData = setsInView.pop();

		// If the first set of IS posts is in the same view as the posts loaded in the template by WordPress, determine how much of the view is comprised of IS-loaded posts
		if ( ( ( windowBottom - setData.top ) / windowSize ) < 0.5 )
			pageNum = -1;
		else
			pageNum = setData.pageNum;
	}
	else {
		var majorityPercentageInView = 0;

		// Identify the IS set that comprises the majority of the current window and set the URL to it.
		$.each( setsInView, function( i, setData ) {
			var topInView     = 0,
				bottomInView  = 0,
				percentOfView = 0;

			// Figure percentage of view the current set represents
			if ( setData.top > windowTop && setData.top < windowBottom )
				topInView = ( windowBottom - setData.top ) / windowSize;

			if ( setData.bottom > windowTop && setData.bottom < windowBottom )
				bottomInView = ( setData.bottom - windowTop ) / windowSize;

			// Figure out largest percentage of view for current set
			if ( topInView >= bottomInView )
				percentOfView = topInView;
			else if ( bottomInView >= topInView )
				percentOfView = bottomInView;

			// Does current set's percentage of view supplant the largest previously-found set?
			if ( percentOfView > majorityPercentageInView ) {
				pageNum = setData.pageNum;
				majorityPercentageInView = percentOfView;
			}
		} );
	}

	// If a page number could be determined, update the URL
	// -1 indicates that the original requested URL should be used.
	if ( 'number' == typeof pageNum ) {
		if ( pageNum != -1 )
			pageNum += ( 0 == self.offset ) ? 1 : self.offset;

		self.updateURL( pageNum );
	}
}

/**
 * Update address bar to reflect archive page URL for a given page number.
 * Checks if URL is different to prevent polution of browser history.
 */
Scroller.prototype.updateURL = function( page ) {
	var self = this,
		pageSlug = -1 == page ? self.origURL : window.location.protocol + '//' + self.history.host + self.history.path.replace( /%d/, page );

	if ( window.location.href != pageSlug )
		history.pushState( null, null, pageSlug );
}

/**
 * Ready, set, go!
 */
$( document ).ready( function() {
	// Check for our variables
	if ( ! infiniteScroll )
		return;

	// Set ajaxurl (for brevity)
	ajaxurl = infiniteScroll.settings.ajaxurl;

	// Set stats, used for tracking stats
	stats = infiniteScroll.settings.stats;

	// Define what type of infinity we have, grab text for click-handle
	type  = infiniteScroll.settings.type;
	text  = infiniteScroll.settings.text;
	totop = infiniteScroll.settings.totop;

	// Initialize the scroller (with the ID of the element from the theme)
	infiniteScroll.scroller = new Scroller( infiniteScroll.settings );
});

/**
 * Monitor user scroll activity to update URL to correspond to archive page for current set of IS posts
 * IE only supports pushState() in v10 and above, so don't bother if those conditions aren't met.
 */
if ( ! isIE || ( isIE && IEVersion >= 10 ) ) {
	$( window ).bind( 'scroll', function() {
		clearTimeout( timer );
		timer = setTimeout( infiniteScroll.scroller.determineURL , 100 );
	});
}

})(jQuery); // Close closure


// Move spinner here
//fgnass.github.com/spin.js#v1.2.4
(function(window, document, undefined) {

/**
 * Copyright (c) 2011 Felix Gnass [fgnass at neteye dot de]
 * Licensed under the MIT license
 */

	var prefixes = ['webkit', 'Moz', 'ms', 'O']; /* Vendor prefixes */
	var animations = {}; /* Animation rules keyed by their name */
	var useCssAnimations;

	/**
	 * Utility function to create elements. If no tag name is given,
	 * a DIV is created. Optionally properties can be passed.
	 */
	function createEl(tag, prop) {
		var el = document.createElement(tag || 'div');
		var n;

		for(n in prop) {
			el[n] = prop[n];
		}
		return el;
	}

	/**
	 * Appends children and returns the parent.
	 */
	function ins(parent /* child1, child2, ...*/) {
		for (var i=1, n=arguments.length; i<n; i++) {
			parent.appendChild(arguments[i]);
		}
		return parent;
	}

	/**
	 * Insert a new stylesheet to hold the @keyframe or VML rules.
	 */
	var sheet = function() {
		var el = createEl('style');
		ins(document.getElementsByTagName('head')[0], el);
		return el.sheet || el.styleSheet;
	}();

	/**
	 * Creates an opacity keyframe animation rule and returns its name.
	 * Since most mobile Webkits have timing issues with animation-delay,
	 * we create separate rules for each line/segment.
	 */
	function addAnimation(alpha, trail, i, lines) {
		var name = ['opacity', trail, ~~(alpha*100), i, lines].join('-');
		var start = 0.01 + i/lines*100;
		var z = Math.max(1-(1-alpha)/trail*(100-start) , alpha);
		var prefix = useCssAnimations.substring(0, useCssAnimations.indexOf('Animation')).toLowerCase();
		var pre = prefix && '-'+prefix+'-' || '';

		if (!animations[name]) {
			sheet.insertRule(
				'@' + pre + 'keyframes ' + name + '{' +
				'0%{opacity:'+z+'}' +
				start + '%{opacity:'+ alpha + '}' +
				(start+0.01) + '%{opacity:1}' +
				(start+trail)%100 + '%{opacity:'+ alpha + '}' +
				'100%{opacity:'+ z + '}' +
				'}', 0);
			animations[name] = 1;
		}
		return name;
	}

	/**
	 * Tries various vendor prefixes and returns the first supported property.
	 **/
	function vendor(el, prop) {
		var s = el.style;
		var pp;
		var i;

		if(s[prop] !== undefined) return prop;
		prop = prop.charAt(0).toUpperCase() + prop.slice(1);
		for(i=0; i<prefixes.length; i++) {
			pp = prefixes[i]+prop;
			if(s[pp] !== undefined) return pp;
		}
	}

	/**
	 * Sets multiple style properties at once.
	 */
	function css(el, prop) {
		for (var n in prop) {
			el.style[vendor(el, n)||n] = prop[n];
		}
		return el;
	}

	/**
	 * Fills in default values.
	 */
	function merge(obj) {
		for (var i=1; i < arguments.length; i++) {
			var def = arguments[i];
			for (var n in def) {
				if (obj[n] === undefined) obj[n] = def[n];
			}
		}
		return obj;
	}

	/**
	 * Returns the absolute page-offset of the given element.
	 */
	function pos(el) {
		var o = {x:el.offsetLeft, y:el.offsetTop};
		while((el = el.offsetParent)) {
			o.x+=el.offsetLeft;
			o.y+=el.offsetTop;
		}
		return o;
	}

	var defaults = {
		lines: 12,            // The number of lines to draw
		length: 7,            // The length of each line
		width: 5,             // The line thickness
		radius: 10,           // The radius of the inner circle
		color: '#000',        // #rgb or #rrggbb
		speed: 1,             // Rounds per second
		trail: 100,           // Afterglow percentage
		opacity: 1/4,         // Opacity of the lines
		fps: 20,              // Frames per second when using setTimeout()
		zIndex: 2e9,          // Use a high z-index by default
		className: 'spinner', // CSS class to assign to the element
		top: 'auto',          // center vertically
		left: 'auto'          // center horizontally
	};

	/** The constructor */
	var Spinner = function Spinner(o) {
		if (!this.spin) return new Spinner(o);
		this.opts = merge(o || {}, Spinner.defaults, defaults);
	};

	Spinner.defaults = {};
	Spinner.prototype = {
		spin: function(target) {
			this.stop();
			var self = this;
			var o = self.opts;
			var el = self.el = css(createEl(0, {className: o.className}), {position: 'relative', zIndex: o.zIndex});
			var mid = o.radius+o.length+o.width;
			var ep; // element position
			var tp; // target position

			if (target) {
				target.insertBefore(el, target.firstChild||null);
				tp = pos(target);
				ep = pos(el);
				css(el, {
					left: (o.left == 'auto' ? tp.x-ep.x + (target.offsetWidth >> 1) : o.left+mid) + 'px',
					top: (o.top == 'auto' ? tp.y-ep.y + (target.offsetHeight >> 1) : o.top+mid)  + 'px'
				});
			}

			el.setAttribute('aria-role', 'progressbar');
			self.lines(el, self.opts);

			if (!useCssAnimations) {
				// No CSS animation support, use setTimeout() instead
				var i = 0;
				var fps = o.fps;
				var f = fps/o.speed;
				var ostep = (1-o.opacity)/(f*o.trail / 100);
				var astep = f/o.lines;

				!function anim() {
					i++;
					for (var s=o.lines; s; s--) {
						var alpha = Math.max(1-(i+s*astep)%f * ostep, o.opacity);
						self.opacity(el, o.lines-s, alpha, o);
					}
					self.timeout = self.el && setTimeout(anim, ~~(1000/fps));
				}();
			}
			return self;
		},
		stop: function() {
			var el = this.el;
			if (el) {
				clearTimeout(this.timeout);
				if (el.parentNode) el.parentNode.removeChild(el);
				this.el = undefined;
			}
			return this;
		},
		lines: function(el, o) {
			var i = 0;
			var seg;

			function fill(color, shadow) {
				return css(createEl(), {
					position: 'absolute',
					width: (o.length+o.width) + 'px',
					height: o.width + 'px',
					background: color,
					boxShadow: shadow,
					transformOrigin: 'left',
					transform: 'rotate(' + ~~(360/o.lines*i) + 'deg) translate(' + o.radius+'px' +',0)',
					borderRadius: (o.width>>1) + 'px'
				});
			}
			for (; i < o.lines; i++) {
				seg = css(createEl(), {
					position: 'absolute',
					top: 1+~(o.width/2) + 'px',
					transform: o.hwaccel ? 'translate3d(0,0,0)' : '',
					opacity: o.opacity,
					animation: useCssAnimations && addAnimation(o.opacity, o.trail, i, o.lines) + ' ' + 1/o.speed + 's linear infinite'
				});
				if (o.shadow) ins(seg, css(fill('#000', '0 0 4px ' + '#000'), {top: 2+'px'}));
				ins(el, ins(seg, fill(o.color, '0 0 1px rgba(0,0,0,.1)')));
			}
			return el;
		},
		opacity: function(el, i, val) {
			if (i < el.childNodes.length) el.childNodes[i].style.opacity = val;
		}
	};

	/////////////////////////////////////////////////////////////////////////
	// VML rendering for IE
	/////////////////////////////////////////////////////////////////////////

	/**
	 * Check and init VML support
	 */
	!function() {
		var s = css(createEl('group'), {behavior: 'url(#default#VML)'});
		var i;

		if (!vendor(s, 'transform') && s.adj) {

			// VML support detected. Insert CSS rules ...
			for (i=4; i--;) sheet.addRule(['group', 'roundrect', 'fill', 'stroke'][i], 'behavior:url(#default#VML)');

			Spinner.prototype.lines = function(el, o) {
				var r = o.length+o.width;
				var s = 2*r;

				function grp() {
					return css(createEl('group', {coordsize: s +' '+s, coordorigin: -r +' '+-r}), {width: s, height: s});
				}

				var margin = -(o.width+o.length)*2+'px';
				var g = css(grp(), {position: 'absolute', top: margin, left: margin});

				var i;

				function seg(i, dx, filter) {
					ins(g,
						ins(css(grp(), {rotation: 360 / o.lines * i + 'deg', left: ~~dx}),
							ins(css(createEl('roundrect', {arcsize: 1}), {
									width: r,
									height: o.width,
									left: o.radius,
									top: -o.width>>1,
									filter: filter
								}),
								createEl('fill', {color: o.color, opacity: o.opacity}),
								createEl('stroke', {opacity: 0}) // transparent stroke to fix color bleeding upon opacity change
							)
						)
					);
				}

				if (o.shadow) {
					for (i = 1; i <= o.lines; i++) {
						seg(i, -2, 'progid:DXImageTransform.Microsoft.Blur(pixelradius=2,makeshadow=1,shadowopacity=.3)');
					}
				}
				for (i = 1; i <= o.lines; i++) seg(i);
				return ins(el, g);
			};
			Spinner.prototype.opacity = function(el, i, val, o) {
				var c = el.firstChild;
				o = o.shadow && o.lines || 0;
				if (c && i+o < c.childNodes.length) {
					c = c.childNodes[i+o]; c = c && c.firstChild; c = c && c.firstChild;
					if (c) c.opacity = val;
				}
			};
		}
		else {
			useCssAnimations = vendor(s, 'animation');
		}
	}();

	window.Spinner = Spinner;

})(window, document);
/*
 * Matt Husby https://github.com/matthusby/spin.js
 * Based on the jquery plugin by Bradley Smith
 * https://gist.github.com/1290439
 */

/*
Add spin to the jQuery object
If color is not passed the spinner will be black
You can now create a spinner using any of the variants below:
$("#el").spin(); // Produces default Spinner
$("#el").spin("small"); // Produces a 'small' Spinner
$("#el").spin("large", "white"); // Produces a 'large' Spinner in white (or any valid CSS color).
$("#el").spin({ ... }); // Produces a Spinner using your custom settings.
$("#el").spin("small-right"); // Pin the small spinner to the right edge
$("#el").spin("{small, medium, large}-{left, right, top, bottom}"); // All options for where to pin
$("#el").spin(false); // Kills the spinner.
*/

( function( $ ) {
	$.fn.spin = function( opts, color ) {
		var presets = {
			"small": { lines: 8, length: 2, width: 2, radius: 3, trail: 60, speed: 1.3 },
			"medium": { lines: 8, length: 4, width: 3, radius: 5, trail: 60, speed: 1.3 },
			"large": { lines: 10, length: 6, width: 4, radius: 7, trail: 60, speed: 1.3 }
		};
		if ( Spinner ) {
			return this.each( function() {
				var $this = $( this ),
					data = $this.data();

				if ( data.spinner ) {
					data.spinner.stop();
					delete data.spinner;
				}
				if ( opts !== false ) {
					var spinner_options;
					if ( typeof opts === "string" ) {
						var spinner_base = opts.indexOf( '-' );
						if( spinner_base == -1 ) {
							spinner_base = opts;
						} else {
							spinner_base = opts.substring( 0, spinner_base );
						}
						if ( spinner_base in presets ) {
							spinner_options = presets[spinner_base];
						} else {
							spinner_options = {};
						}
						var padding;
						if ( opts.indexOf( "-right" ) != -1 ) {
							padding = jQuery( this ).css( 'padding-left' );
							if( typeof padding === "undefined" ) {
								padding = 0;
							} else {
								padding = padding.replace( 'px', '' );
							}
							spinner_options.left = jQuery( this ).outerWidth() - ( 2 * ( spinner_options.length + spinner_options.width + spinner_options.radius ) ) - padding - 5;
						}
						if ( opts.indexOf( '-left' ) != -1 ) {
							spinner_options.left = 5;
						}
						if ( opts.indexOf( '-top' ) != -1 ) {
							spinner_options.top = 5;
						}
						if ( opts.indexOf( '-bottom' ) != -1 ) {
							padding = jQuery( this ).css( 'padding-top' );
							if( typeof padding === "undefined" ) {
								padding = 0;
							} else {
								padding = padding.replace( 'px', '' );
							}
							spinner_options.top = jQuery( this ).outerHeight() - ( 2 * ( spinner_options.length + spinner_options.width + spinner_options.radius ) ) - padding - 5;
						}
					}
					if( color ){
						spinner_options.color = color;
					}
					data.spinner = new Spinner( spinner_options ).spin( this );
				}
			});
		} else {
			throw "Spinner class not available.";
		}
	};
})( jQuery );